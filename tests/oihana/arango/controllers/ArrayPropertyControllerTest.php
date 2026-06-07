<?php

namespace tests\oihana\arango\controllers;

use oihana\arango\controllers\ArrayPropertyController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;

use PHPUnit\Framework\Attributes\CoversClass;

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

    private function controller( MockDocuments $model ) :ArrayPropertyController
    {
        return $this->makeArrayPropertyController( $model , [ self::PROPERTY => 'tracks' ] ) ;
    }

    // ---- success paths --------------------------------------------------

    public function testAddItemReturnsUpdatedProperty() :void
    {
        $controller = $this->controller( $this->model() ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ Arango::VALUE => 'C' ] ) ;

        $this->assertSame( [ 'A' , 'B' ] , $controller->addItem( $request , null , [ Arango::ID => 'p42' ] ) ) ;
    }

    public function testRemoveItemUsesUrlValue() :void
    {
        $controller = $this->controller( $this->model() ) ;

        $this->assertSame
        (
            [ 'A' , 'B' ] ,
            $controller->removeItem( null , null , [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] )
        ) ;
    }

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

    public function testHasItemPresentReturnsTrue() :void
    {
        $controller = $this->controller( $this->model() ) ; // firstResult = 1 → present

        $this->assertTrue( $controller->hasItem( null , null , [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ) ) ;
    }

    // ---- error paths ----------------------------------------------------

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
