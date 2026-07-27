<?php

namespace tests\oihana\arango\controllers;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\controllers\ArrayPropertyController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;

use PHPUnit\Framework\Attributes\CoversClass;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use tests\oihana\arango\controllers\mocks\ThrowingDocuments;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for {@see ArrayPropertyController} — element-level operations on an
 * embedded array property. Success paths run with a null response so
 * `success()` returns the property value directly; error paths run with a real
 * response so the HTTP status code can be asserted.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( ArrayPropertyController::class )]
class ArrayPropertyControllerTest extends ControllerTestCase
{
    /** The `property` init key (PropertyTrait::PROPERTY, a trait constant). */
    private const string PROPERTY = 'property' ;

    /** A MockDocuments wired with a `tracks` LIST array field and a canned NEW doc. */
    private function model( string $mode = ArrayMode::LIST ) :MockDocuments
    {
        $model = new MockDocuments( 'Playlist' ) ;
        $model->arrays       = [ 'tracks' => [ Arango::MODE => $mode , Arango::COUNTER => null ] ] ;
        $model->firstResult  = 1 ; // exist() → true / arrayContains() → true
        $model->objectResult = (object) [ '_key' => 'p42' , 'tracks' => [ 'A' , 'B' ] ] ;
        return $model ;
    }

    /**
     * A MockDocuments whose `chapters` property is targeted **by key** (`id`), with a
     * canned NEW doc holding the two elements the write is supposed to have returned.
     *
     * They are associative **arrays**, which is the shape a hydrated document really
     * carries for its nested elements — see {@see testUpdateItemAlsoMatchesObjectElements()}
     * for the object shape, which the lookup handles just as well.
     */
    private function keyedModel() :MockDocuments
    {
        $model = new MockDocuments( 'Playlist' ) ;
        $model->arrays       = [ 'chapters' => [ Arango::MODE => ArrayMode::LIST , Arango::COUNTER => null , Arango::ITEM_KEY => 'id' ] ] ;
        $model->firstResult  = 1 ; // exist() → true
        $model->objectResult = (object)
        [
            '_key'     => 'p42' ,
            'chapters' =>
            [
                [ 'id' => 'c1' , 'title' => 'Intro'  , 'rating' => 5 ] ,
                [ 'id' => 'c2' , 'title' => 'Chorus' , 'rating' => 3 ] ,
            ] ,
        ] ;
        return $model ;
    }

    /**
     * @param MockDocuments $model
     * @return ArrayPropertyController
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function controller( MockDocuments $model ) :ArrayPropertyController
    {
        return $this->makeArrayPropertyController( $model , [ self::PROPERTY => 'tracks' ] ) ;
    }

    /**
     * @param MockDocuments $model
     * @return ArrayPropertyController
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function keyedController( MockDocuments $model ) :ArrayPropertyController
    {
        return $this->makeArrayPropertyController( $model , [ self::PROPERTY => 'chapters' ] ) ;
    }

    // ---- success paths --------------------------------------------------

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testAddItemReturnsUpdatedProperty() :void
    {
        $controller = $this->controller( $this->model() ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ Arango::VALUE => 'C' ] ) ;

        $this->assertSame( [ 'A' , 'B' ] , $controller->addItem( $request , null , [ Arango::ID => 'p42' ] ) ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testRemoveItemUsesUrlValue() :void
    {
        $controller = $this->controller( $this->model() ) ;

        $this->assertSame
        (
            [ 'A' , 'B' ] ,
            $controller->removeItem( null , null , [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] )
        ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testMoveItemUsesPositionFromBody() :void
    {
        $controller = $this->controller( $this->model() ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ Arango::POSITION => 1 ] ) ;

        $this->assertSame
        (
            [ 'A' , 'B' ] ,
            $controller->moveItem( $request , null , [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] )
        ) ;
    }

    /**
     * The ordered keys travel in the body under `value`, like {@see addItem()} — the
     * other operation targeting the property rather than one of its elements.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testReorderItemsUsesTheOrderedKeysFromTheBody() :void
    {
        $model      = $this->keyedModel() ;
        $controller = $this->keyedController( $model ) ;
        $request    = $this->makeRequest( [] , 'PUT' )->withParsedBody( [ Arango::VALUE => [ 'c2' , 'c1' ] ] ) ;

        $items = $controller->reorderItems( $request , null , [ Arango::ID => 'p42' ] ) ;

        $this->assertSame( $model->objectResult->chapters , $items ) ;
        $this->assertStringContainsString( 'LET __ord = (FOR __k IN @' , $model->lastQuery ) ;
        $this->assertContains( [ 'c2' , 'c1' ] , $model->lastBinds ) ;
    }

    /**
     * Ordering elements needs an attribute identifying them — the model states the rule,
     * the shared skeleton turns it into a 422.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testReorderItemsWithoutAnItemKeyReturns422() :void
    {
        $response = $this->controller( $this->model() )->reorderItems
        (
            $this->makeRequest( [] , 'PUT' )->withParsedBody( [ Arango::VALUE => [ 'A' ] ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' ]
        ) ;

        $this->assertSame( 422 , $response->getStatusCode() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testReorderItemsOnSortedSetReturns422() :void
    {
        $model = $this->keyedModel() ;
        $model->arrays[ 'chapters' ][ Arango::MODE ] = ArrayMode::SORTED_SET ;

        $response = $this->keyedController( $model )->reorderItems
        (
            $this->makeRequest( [] , 'PUT' )->withParsedBody( [ Arango::VALUE => [ 'c1' ] ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' ]
        ) ;

        $this->assertSame( 422 , $response->getStatusCode() ) ;
    }

    /**
     * A field declared both sortedSet and ranked cannot ever be written: it is the same
     * kind of refusal, so it answers with the same status.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testAddItemOnASortedSetDeclaringAPositionKeyReturns422() :void
    {
        $model = $this->model( ArrayMode::SORTED_SET ) ;
        $model->arrays[ 'tracks' ][ Arango::POSITION_KEY ] = 'position' ;

        $response = $this->controller( $model )->addItem
        (
            $this->makeRequest( [] , 'POST' )->withParsedBody( [ Arango::VALUE => 'A' ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' ]
        ) ;

        $this->assertSame( 422 , $response->getStatusCode() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testHasItemPresentReturnsTrue() :void
    {
        $controller = $this->controller( $this->model() ) ; // firstResult = 1 → present

        $this->assertTrue( $controller->hasItem( null , null , [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ) ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testUpdateItemMergesTheBodyAsThePatch() :void
    {
        $model      = $this->keyedModel() ;
        $controller = $this->keyedController( $model ) ;
        // the body IS the patch — no envelope
        $request    = $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'rating' => 5 ] ) ;

        $this->assertSame
        (
            $model->objectResult->chapters ,
            $controller->updateItem( $request , null , [ Arango::ID => 'p42' , Arango::VALUE => 'c1' ] )
        ) ;
    }

    /**
     * The lookup reads an element whichever shape it comes back in.
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testUpdateItemAlsoMatchesObjectElements() :void
    {
        $model = $this->keyedModel() ;
        $model->objectResult = (object) [ '_key' => 'p42' , 'chapters' => [ (object) [ 'id' => 'c1' , 'title' => 'Intro' ] ] ] ;

        $this->assertSame
        (
            $model->objectResult->chapters ,
            $this->keyedController( $model )->updateItem
            (
                $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'rating' => 5 ] ) ,
                null ,
                [ Arango::ID => 'p42' , Arango::VALUE => 'c1' ]
            )
        ) ;
    }

    /**
     * A property targeted by value keeps its 200: the post-check only runs on a keyed one.
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testMoveItemByValueIsUnaffectedByThePostCheck() :void
    {
        $controller = $this->controller( $this->model() ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ Arango::POSITION => 1 ] ) ;

        $this->assertSame
        (
            [ 'A' , 'B' ] ,
            $controller->moveItem( $request , null , [ Arango::ID => 'p42' , Arango::VALUE => 'Z' ] )
        ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testMoveItemByKeyReturnsTheUpdatedProperty() :void
    {
        $model      = $this->keyedModel() ;
        $controller = $this->keyedController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ Arango::POSITION => 1 ] ) ;

        $this->assertSame
        (
            $model->objectResult->chapters ,
            $controller->moveItem( $request , null , [ Arango::ID => 'p42' , Arango::VALUE => 'c2' ] )
        ) ;
    }

    // ---- error paths ----------------------------------------------------

    /**
     * The model rewrote the array unchanged (the key matches no element), so the
     * returned document is what proves the miss — no second query.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testUpdateItemUnknownKeyReturns404() :void
    {
        $response = $this->keyedController( $this->keyedModel() )->updateItem
        (
            $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'rating' => 5 ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' , Arango::VALUE => 'nope' ]
        ) ;

        $this->assertSame( 404 , $response->getStatusCode() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testMoveItemUnknownKeyReturns404() :void
    {
        $response = $this->keyedController( $this->keyedModel() )->moveItem
        (
            $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ Arango::POSITION => 0 ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' , Arango::VALUE => 'nope' ]
        ) ;

        $this->assertSame( 404 , $response->getStatusCode() ) ;
    }

    /**
     * The comparison is strict, like AQL's `==` on a document attribute: a numeric key
     * requested as a string matches nothing on either side.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testUpdateItemDoesNotMatchAKeyOfAnotherType() :void
    {
        $model = $this->keyedModel() ;
        $model->objectResult = (object) [ '_key' => 'p42' , 'chapters' => [ (object) [ 'id' => 1 , 'title' => 'Intro' ] ] ] ;

        $response = $this->keyedController( $model )->updateItem
        (
            $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'rating' => 5 ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' , Arango::VALUE => '1' ] // the string from the URL
        ) ;

        $this->assertSame( 404 , $response->getStatusCode() ) ;
    }

    /**
     * An element with no attribute at all can never carry a key.
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testUpdateItemOnScalarElementsReturns404() :void
    {
        $model = $this->keyedModel() ;
        $model->objectResult = (object) [ '_key' => 'p42' , 'chapters' => [ 'A' , 'B' ] ] ;

        $response = $this->keyedController( $model )->updateItem
        (
            $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'rating' => 5 ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' , Arango::VALUE => 'A' ]
        ) ;

        $this->assertSame( 404 , $response->getStatusCode() ) ;
    }

    /**
     * The write matched no document at all — the owner vanished between the `exist()`
     * probe and the update — so there is no array to look the key up in.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testUpdateItemReturns404WhenTheWriteMatchedNothing() :void
    {
        $model = $this->keyedModel() ;
        $model->objectResult = null ; // RETURN NEW yielded nothing

        $response = $this->keyedController( $model )->updateItem
        (
            $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'rating' => 5 ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' , Arango::VALUE => 'c1' ]
        ) ;

        $this->assertSame( 404 , $response->getStatusCode() ) ;
    }

    /**
     * A property targeted by value cannot be edited in place: designating its element
     * would need a byte-for-byte copy that the patch itself invalidates.
     *
     * @return void
     */
    public function testUpdateItemWithoutAnItemKeyReturns422() :void
    {
        $response = $this->controller( $this->model() )->updateItem
        (
            $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'rating' => 5 ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' , Arango::VALUE => 'A' ]
        ) ;

        $this->assertSame( 422 , $response->getStatusCode() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testHasItemAbsentReturns404() :void
    {
        $model = $this->model() ;
        $model->firstResult = 0 ; // arrayContains() → false
        $response = $this->controller( $model )->hasItem
        (
            $this->makeRequest( [] , 'GET' ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' , Arango::VALUE => 'Z' ]
        ) ;

        $this->assertSame( 404 , $response->getStatusCode() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testMoveItemOnSortedSetReturns422() :void
    {
        $response = $this->controller( $this->model( ArrayMode::SORTED_SET ) )->moveItem
        (
            $this->makeRequest( [] , 'PATCH' ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' , Arango::VALUE => 'A' ]
        ) ;

        $this->assertSame( 422 , $response->getStatusCode() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testRejectsNonArrayPropertyWith400() :void
    {
        $model = $this->model() ;
        $model->arrays = [] ; // 'tracks' is not a declared array field
        $response = $this->controller( $model )->addItem
        (
            $this->makeRequest( [] , 'POST' )->withParsedBody( [ Arango::VALUE => 'C' ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' ]
        ) ;

        $this->assertSame( 400 , $response->getStatusCode() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testReturns404WhenDocumentMissing() :void
    {
        $model = $this->model() ;
        $model->firstResult = 0 ; // exist() → false
        $response = $this->controller( $model )->addItem
        (
            $this->makeRequest( [] , 'POST' )->withParsedBody( [ Arango::VALUE => 'C' ] ) ,
            $this->makeResponse() ,
            [ Arango::ID => 'p42' ]
        ) ;

        $this->assertSame( 404 , $response->getStatusCode() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testModelFailureIsCaught() :void
    {
        $model = new ThrowingDocuments( 'Playlist' ) ;
        $model->arrays = [ 'tracks' => [ Arango::MODE => ArrayMode::LIST , Arango::COUNTER => null ] ] ;
        $controller = $this->makeArrayPropertyController( $model , [ self::PROPERTY => 'tracks' ] ) ;

        // exist() throws (getFirstResult) → catch → fail() → null on a null response
        $this->assertNull
        (
            $controller->addItem
            (
                $this->makeRequest( [] , 'POST' )->withParsedBody( [ Arango::VALUE => 'C' ] ) ,
                null ,
                [ Arango::ID => 'p42' ]
            )
        ) ;
    }
}
