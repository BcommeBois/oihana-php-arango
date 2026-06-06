<?php

namespace tests\oihana\arango\controllers;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\controllers\enums\AQLType;
use oihana\arango\enums\Arango;
use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\controllers\mocks\ThrowingDocuments;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for the write handlers of {@see DocumentsController}: post / delete /
 * update (patch / put). Drives the real controller with null response so
 * `success()` returns raw data, and uses a parsed-body request for the payload
 * paths.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( DocumentsController::class )]
class DocumentsControllerWriteTest extends ControllerTestCase
{
    // ---- post -----------------------------------------------------------

    public function testPostInsertsThenReturnsTheReloadedDocument() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' , 'name' => 'Alice' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'Alice' ] ) ;

        $result = $controller->post( $request , null , [] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
    }

    public function testPostRawReturnsTheInsertResultDirectly() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'X' ] ) ;

        $result = $controller->post( $request , null , [ Arango::RAW => true ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
    }

    public function testPostReturnsValidatorErrorWhenPayloadInvalid() :void
    {
        $model = new MockDocuments( 'users' ) ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::RULES => [ HttpMethod::ALL => [ 'missing' => 'required' ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'X' ] ) ;

        // required field absent → validation fails → getValidatorError (null on null response)
        $this->assertNull( $controller->post( $request , null , [] ) ) ;
    }

    public function testPostReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'X' ] ) ;

        $this->assertNull( $controller->post( $request , null , [] ) ) ;
    }

    public function testPostReturnsUnprocessableWhenI18nFieldIsFlat() :void
    {
        $model = new MockDocuments( 'users' ) ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::PAYLOAD => [ HttpMethod::ALL => [ 'title' => [ Arango::TYPE => AQLType::I18N ] ] ] ,
        ] ) ;

        // a flat string where a per-language object is expected → 422 (needs a real
        // response so enforceI18nShape returns a truthy Response to short-circuit)
        $request = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'title' => 'flat' ] ) ;

        $result = $controller->post( $request , $this->makeResponse() , [] ) ;

        $this->assertSame( HttpStatusCode::UNPROCESSABLE_ENTITY , $result->getStatusCode() ) ;
    }

    public function testPostStripsRelationKeysFromTheInsertPayload() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::PAYLOAD => [ HttpMethod::ALL => [ 'tags' => [ Arango::TYPE => AQLType::EDGE ] ] ] ,
        ] ) ;

        // 'tags' is an edge relation → registered in $relations and stripped from the doc
        $request = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'X' , 'tags' => 'roles/admin' ] ) ;

        $this->assertSame( $model->objectResult , $controller->post( $request , null , [] ) ) ;
    }

    // ---- delete ---------------------------------------------------------

    public function testDeleteByArgsIdReturnsDeletedKey() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ; // single id → single OLD document

        $controller = $this->makeDocumentsController( $model ) ;

        // EXIST=true bypasses the existence check
        $result = $controller->delete( null , null , [ Arango::ID => 'k1' ] , [ Arango::EXIST => true ] ) ;

        $this->assertSame( 'k1' , $result ) ;
    }

    public function testDeleteByQueryParamSplitsSortsAndDeduplicates() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult     = 2 ; // exist(): result === count(ids) → 2 unique ids
        $model->documentsResult = [ (object) [ '_key' => 'a' ] , (object) [ '_key' => 'b' ] ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [ Arango::ID => 'b,a,b' ] , 'DELETE' ) ;

        $result = $controller->delete( $request , null , [] ) ;

        $this->assertSame( [ 'a' , 'b' ] , $result ) ;
    }

    public function testDeleteWithoutIdReturnsBadRequest() :void
    {
        $controller = $this->makeDocumentsController( new MockDocuments( 'users' ) ) ;

        $this->assertNull( $controller->delete( null , null , [] , [] ) ) ;
    }

    public function testDeleteReturnsNotFoundWhenDocumentMissing() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 0 ; // exist() → false

        $controller = $this->makeDocumentsController( $model ) ;

        $this->assertNull( $controller->delete( null , null , [ Arango::ID => 'ghost' ] , [] ) ) ;
    }

    public function testDeleteReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;

        $this->assertNull( $controller->delete( null , null , [ Arango::ID => 'k1' ] , [ Arango::EXIST => true ] ) ) ;
    }

    // ---- update (patch / put) -------------------------------------------

    public function testPatchUpdatesAndReturnsReloadedDocument() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ; // exist() → true
        $model->objectResult = (object) [ '_key' => 'k1' , 'a' => 1 ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $this->assertSame( $model->objectResult , $controller->patch( $request , null , [ 'id' => 'k1' ] ) ) ;
    }

    public function testPutReplacesAndReturnsReloadedDocument() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = (object) [ '_key' => 'k1' , 'a' => 2 ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'a' => 2 ] ) ;

        $this->assertSame( $model->objectResult , $controller->put( $request , null , [ 'id' => 'k1' ] ) ) ;
    }

    public function testUpdateRawReturnsTheWriteResultDirectly() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $this->assertSame( $model->objectResult , $controller->patch( $request , null , [ 'id' => 'k1' ] , [ Arango::RAW => true ] ) ) ;
    }

    public function testUpdateReturnsNotFoundWhenDocumentMissing() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 0 ; // exist() → false

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $this->assertNull( $controller->patch( $request , null , [ 'id' => 'ghost' ] ) ) ;
    }

    public function testUpdateReturnsValidatorErrorWhenPayloadInvalid() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 1 ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::RULES => [ HttpMethod::ALL => [ 'missing' => 'required' ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $this->assertNull( $controller->patch( $request , null , [ 'id' => 'k1' ] ) ) ;
    }

    public function testUpdateReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $this->assertNull( $controller->patch( $request , null , [ 'id' => 'k1' ] ) ) ;
    }

    public function testUpdateReturnsUnprocessableWhenI18nFieldIsFlat() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 1 ; // exist() → true so the flow reaches the i18n check

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::PAYLOAD => [ HttpMethod::ALL => [ 'title' => [ Arango::TYPE => AQLType::I18N ] ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'title' => 'flat' ] ) ;

        $result = $controller->patch( $request , $this->makeResponse() , [ 'id' => 'k1' ] ) ;

        $this->assertSame( HttpStatusCode::UNPROCESSABLE_ENTITY , $result->getStatusCode() ) ;
    }

    public function testUpdateStripsRelationKeysFromThePayload() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::PAYLOAD => [ HttpMethod::ALL => [ 'tags' => [ Arango::TYPE => AQLType::EDGE ] ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 , 'tags' => 'roles/admin' ] ) ;

        $this->assertSame( $model->objectResult , $controller->patch( $request , null , [ 'id' => 'k1' ] ) ) ;
    }
}
