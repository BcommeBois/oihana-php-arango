<?php

namespace tests\oihana\arango\controllers;

use oihana\arango\controllers\PropertyController;
use oihana\arango\enums\Arango;
use oihana\controllers\enums\ControllerParam;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\controllers\mocks\ThrowingDocuments;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for {@see PropertyController} — reads (get) or updates (patch) a
 * single property of a document. Driven through the real controller with null
 * response so `success()` returns the property value directly.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( PropertyController::class )]
class PropertyControllerTest extends ControllerTestCase
{
    /** The `property` init key (PropertyTrait::PROPERTY, a trait constant). */
    private const string PROPERTY = 'property' ;

    // ---- get ------------------------------------------------------------

    public function testGetReturnsTheRequestedProperty() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ 'emails' => [ 'a@x' , 'b@x' ] ] ;

        $controller = $this->makePropertyController( $model , [ self::PROPERTY => 'emails' ] ) ;

        $this->assertSame( [ 'a@x' , 'b@x' ] , $controller->get( null , null , [ Arango::ID => 'k1' ] ) ) ;
    }

    public function testGetReturnsNullWhenPropertyNotConfigured() :void
    {
        // no `property` init → assertProperty() throws → fail() (null on null response)
        $controller = $this->makePropertyController( new MockDocuments( 'users' ) ) ;

        $this->assertNull( $controller->get( null , null , [ Arango::ID => 'k1' ] ) ) ;
    }

    public function testGetReturnsNullOnModelFailure() :void
    {
        $controller = $this->makePropertyController
        (
            new ThrowingDocuments( 'users' ) ,
            [ self::PROPERTY => 'emails' ]
        ) ;

        $this->assertNull( $controller->get( null , null , [ Arango::ID => 'k1' ] ) ) ;
    }

    // ---- patch ----------------------------------------------------------

    public function testPatchUpdatesPropertyAndReturnsReloadedValue() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ; // exist() → true
        $model->objectResult = (object) [ '_key' => 'k1' , 'emails' => [ 'new@x' ] ] ;

        $controller = $this->makePropertyController( $model , [ self::PROPERTY => 'emails' ] ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [ 'new@x' ] ] ) ;

        $this->assertSame( [ 'new@x' ] , $controller->patch( $request , null , [ Arango::ID => 'k1' ] ) ) ;
    }

    public function testPatchRawReturnsNullBecausePayloadIsArrayShaped() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = (object) [ '_key' => 'k1' , 'emails' => [ 'ignored@x' ] ] ;

        $controller = $this->makePropertyController( $model , [ self::PROPERTY => 'emails' ] ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [ 'raw@x' ] ] ) ;

        // NOTE (characterization): in raw mode the handler returns
        // `$payload->{$this->property}`, but propertyPayload() yields an ARRAY
        // ([property => body]), so the object-property access resolves to null.
        // Behavior frozen as-is; flagged for review (see potential_fixes_registry).
        $this->assertNull( $controller->patch( $request , null , [ Arango::ID => 'k1' ] , [ Arango::RAW => true ] ) ) ;
    }

    public function testPatchReturnsNotFoundWhenDocumentMissing() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 0 ; // exist() → false

        $controller = $this->makePropertyController( $model , [ self::PROPERTY => 'emails' ] ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [] ] ) ;

        $this->assertNull( $controller->patch( $request , null , [ Arango::ID => 'ghost' ] ) ) ;
    }

    public function testPatchReturnsNullWhenPropertyNotConfigured() :void
    {
        $controller = $this->makePropertyController( new MockDocuments( 'users' ) ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [] ] ) ;

        $this->assertNull( $controller->patch( $request , null , [ Arango::ID => 'k1' ] ) ) ;
    }

    public function testPatchReturnsValidatorErrorWhenPayloadInvalid() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 1 ;

        $controller = $this->makePropertyController( $model , [
            self::PROPERTY         => 'emails' ,
            // propertyPayload always yields [ property => body ], so require a field
            // that is never present to make the validator fail deterministically
            ControllerParam::RULES => [ 'nonexistent' => 'required' ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [ 'x@y' ] ] ) ;

        $this->assertNull( $controller->patch( $request , null , [ Arango::ID => 'k1' ] ) ) ;
    }

    public function testPatchReturnsNullOnModelFailure() :void
    {
        $controller = $this->makePropertyController
        (
            new ThrowingDocuments( 'users' ) ,
            [ self::PROPERTY => 'emails' ]
        ) ;
        $request = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [] ] ) ;

        $this->assertNull( $controller->patch( $request , null , [ Arango::ID => 'k1' ] ) ) ;
    }
}
