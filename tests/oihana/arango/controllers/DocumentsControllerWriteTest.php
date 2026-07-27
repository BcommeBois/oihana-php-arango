<?php

namespace tests\oihana\arango\controllers;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\controllers\enums\AQLType;
use oihana\arango\enums\Arango;
use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\controllers\mocks\RecordingDocuments;
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

    /**
     * A refused payload must never reach the model, and that is what is asserted —
     * not the returned value.
     *
     * With a null response `fail()` returns null, so `assertNull()` alone proves
     * nothing: it is also what a write that ran and produced nothing returns. This
     * test used to pass through exactly that path — validation refused the payload,
     * the insert ran anyway, the mock returned nothing, and the "write matched
     * nothing" guard produced the expected null. Recording the model calls is the
     * only assertion no chain of accidents can satisfy.
     */
    public function testPostRefusesAnInvalidPayloadWithoutTouchingTheModel() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ; // a write would succeed, if one ran

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::RULES => [ HttpMethod::ALL => [ 'missing' => 'required' ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'X' ] ) ;

        $this->assertNull( $controller->post( $request , null , [] ) ) ;
        $this->assertSame( [] , $model->methods() , 'the model was reached despite the validation failure' ) ;
    }

    /**
     * The same guard, seen from the HTTP side: with a real response the refusal is
     * a 400 carrying the rule errors.
     */
    public function testPostAnswers400WhenPayloadInvalid() :void
    {
        $model = new RecordingDocuments( 'users' ) ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::RULES => [ HttpMethod::ALL => [ 'missing' => 'required' ] ] ,
        ] ) ;

        $request  = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'X' ] ) ;
        $response = $controller->post( $request , $this->makeResponse() , [] ) ;

        $this->assertSame( 400 , $response->getStatusCode() ) ;
        $this->assertSame( [] , $model->methods() ) ;
    }

    /**
     * The i18n shape guard short-circuits on a null response too. It used to need a
     * real one — the test below had to say so in a comment — because the guard
     * branched on the truthiness of the error response instead of on the errors.
     */
    public function testPostRefusesAMalformedI18nFieldWithoutTouchingTheModel() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::PAYLOAD => [ HttpMethod::ALL => [ 'title' => [ Arango::TYPE => AQLType::I18N ] ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'title' => 'flat' ] ) ;

        $this->assertNull( $controller->post( $request , null , [] ) ) ;
        $this->assertSame( [] , $model->methods() , 'the model was reached despite the malformed i18n field' ) ;
    }

    public function testPostReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'X' ] ) ;

        $this->assertNull( $controller->post( $request , null , [] ) ) ;
    }

    /**
     * `INSERT … RETURN NEW` always yields the created document, so a null is not a
     * client mistake to translate into a 4xx: the write reported success and
     * produced nothing. The guard exists because the reload dereferences it, and
     * an `Error` would escape `catch( Exception )` instead of the error envelope.
     */
    public function testPostAnswers500WhenTheInsertReturnsNothing() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = null ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'Alice' ] ) ;
        $response   = $controller->post( $request , $this->makeResponse() , [] ) ;

        $this->assertSame( HttpStatusCode::INTERNAL_SERVER_ERROR , $response->getStatusCode() ) ;
    }

    public function testPostReturnsUnprocessableWhenI18nFieldIsFlat() :void
    {
        $model = new MockDocuments( 'users' ) ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::PAYLOAD => [ HttpMethod::ALL => [ 'title' => [ Arango::TYPE => AQLType::I18N ] ] ] ,
        ] ) ;

        // a flat string where a per-language object is expected → 422
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

    public function testPostPassesTheRouteArgsToTheInsertInit() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'Alice' ] ) ;

        $args = [ 'workspace' => 'w1' , 'observation' => '15454' ] ;

        $controller->post( $request , null , $args ) ;

        // the write init carries the route args, and so does the reload
        $this->assertSame( $args , $model->initOf( 'insert' )[ Arango::ARGS ] ?? null ) ;
        $this->assertSame( $args , $model->initOf( 'get'    )[ Arango::ARGS ] ?? null ) ;
    }

    public function testPostPassesAnEmptyArgsArrayWhenTheRouteHasNoPlaceholder() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'Alice' ] ) ;

        $controller->post( $request , null , [] ) ;

        // the key is always present (never absent) so the model can read it blindly
        $this->assertArrayHasKey( Arango::ARGS , $model->initOf( 'insert' ) ) ;
        $this->assertSame( [] , $model->initOf( 'insert' )[ Arango::ARGS ] ) ;
    }

    public function testPostArgsAreVisibleAlongsideTheDocumentAndTheRelations() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::PAYLOAD => [ HttpMethod::ALL => [ 'tags' => [ Arango::TYPE => AQLType::EDGE ] ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'X' , 'tags' => 'roles/admin' ] ) ;

        $controller->post( $request , null , [ 'workspace' => 'w1' ] ) ;

        $init = $model->initOf( 'insert' ) ;

        $this->assertSame( [ 'workspace' => 'w1' ] , $init[ Arango::ARGS ] ) ;
        $this->assertArrayHasKey   ( 'tags' , $init[ Arango::RELATIONS ] ) ;
        $this->assertArrayNotHasKey( 'tags' , (array) $init[ Arango::DOC ] ) ;
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

    public function testDeletePassesTheRouteArgsToBothTheExistenceProbeAndTheDeleteInit() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult  = 1 ; // exist() → true
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        $args = [ Arango::ID => 'k1' , 'workspace' => 'w1' ] ;

        $this->assertSame( 'k1' , $controller->delete( null , null , $args ) ) ;

        // the init is built once, before exist(), so both calls see the args
        $this->assertSame( [ 'exist' , 'delete' ] , $model->methods() ) ;
        $this->assertSame( $args , $model->initOf( 'exist'  )[ Arango::ARGS ] ?? null ) ;
        $this->assertSame( $args , $model->initOf( 'delete' )[ Arango::ARGS ] ?? null ) ;
    }

    public function testDeleteByQueryParamAlsoPassesTheRouteArgs() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->documentsResult = [ (object) [ '_key' => 'a' ] , (object) [ '_key' => 'b' ] ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [ Arango::ID => 'b,a' ] , 'DELETE' ) ;

        // bulk delete: the ids come from the query string, the args from the route
        $controller->delete( $request , null , [ 'workspace' => 'w1' ] , [ Arango::EXIST => true ] ) ;

        $init = $model->initOf( 'delete' ) ;

        $this->assertSame( [ 'workspace' => 'w1' ] , $init[ Arango::ARGS  ] ) ;
        $this->assertSame( [ 'a' , 'b' ]           , $init[ Arango::VALUE ] ) ;
    }

    public function testDeleteRouteArgsOverrideAnyArgsAlreadyPresentInInit() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        $result = $controller->delete( null , null , [ Arango::ID => 'k1' , 'workspace' => 'route' ] ,
        [
            Arango::EXIST => true ,
            Arango::ARGS  => [ 'workspace' => 'stale' ] ,
        ]) ;

        $this->assertSame( 'k1' , $result ) ;
        $this->assertSame( [ Arango::ID => 'k1' , 'workspace' => 'route' ] , $model->initOf( 'delete' )[ Arango::ARGS ] ) ;
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

    /**
     * The document passed the existence probe, then vanished before the write ran
     * (another request deleted it). `UPDATE … RETURN NEW` matches nothing and
     * yields null, which the reload used to dereference — raising an `Error`,
     * which `catch( Exception )` does not intercept. It is the same fact the probe
     * reports, so it answers with the same status.
     */
    public function testPatchAnswers404WhenTheDocumentVanishedBeforeTheWrite() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ;    // exist() → true
        $model->objectResult = null ; // … but the write matched nothing

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;
        $response   = $controller->patch( $request , $this->makeResponse() , [ 'id' => 'k1' ] ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $response->getStatusCode() ) ;
    }

    public function testPutAnswers404WhenTheDocumentVanishedBeforeTheWrite() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = null ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'a' => 2 ] ) ;
        $response   = $controller->put( $request , $this->makeResponse() , [ 'id' => 'k1' ] ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $response->getStatusCode() ) ;
    }

    /**
     * Raw mode never crashed — it returns the write result without reloading — but
     * it answered 200 with a null body for a write that touched nothing, which
     * reads as success. It is gated by the same gu ard.
     */
    public function testUpdateRawAlsoAnswers404WhenTheWriteMatchedNothing() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = null ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;
        $response   = $controller->patch( $request , $this->makeResponse() , [ 'id' => 'k1' ] , [ Arango::RAW => true ] ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $response->getStatusCode() ) ;
    }

    /**
     * Same guard on the update path. The existence probe has already run by then, so
     * the assertion targets the write itself: `update` must not appear among the
     * recorded calls.
     */
    public function testUpdateRefusesAnInvalidPayloadWithoutWriting() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult  = 1 ;                          // exist() → true
        $model->objectResult = (object) [ '_key' => 'k1' ] ; // a write would succeed, if one ran

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::RULES => [ HttpMethod::ALL => [ 'missing' => 'required' ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $this->assertNull( $controller->patch( $request , null , [ 'id' => 'k1' ] ) ) ;
        $this->assertSame( [ 'exist' ] , $model->methods() , 'the write ran despite the validation failure' ) ;
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

    public function testPatchPassesTheRouteArgsToTheUpdateInit() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult  = 1 ; // exist() → true
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $args = [ 'id' => 'k1' , 'workspace' => 'w1' ] ;

        $controller->patch( $request , null , $args ) ;

        $this->assertSame( $args , $model->initOf( 'update' )[ Arango::ARGS ] ?? null ) ;
        $this->assertSame( $args , $model->initOf( 'get'    )[ Arango::ARGS ] ?? null ) ;
        $this->assertNull( $model->initOf( 'replace' ) ) ;
    }

    public function testPutPassesTheRouteArgsToTheReplaceInit() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'a' => 2 ] ) ;

        $args = [ 'id' => 'k1' , 'workspace' => 'w1' ] ;

        $controller->put( $request , null , $args ) ;

        $this->assertSame( $args , $model->initOf( 'replace' )[ Arango::ARGS ] ?? null ) ;
        $this->assertNull( $model->initOf( 'update' ) ) ;
    }

    public function testUpdateRouteArgsOverrideAnyArgsAlreadyPresentInInit() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $controller->patch( $request , null , [ 'id' => 'k1' , 'workspace' => 'route' ] ,
        [
            Arango::ARGS => [ 'workspace' => 'stale' ] ,
        ]) ;

        $this->assertSame( [ 'id' => 'k1' , 'workspace' => 'route' ] , $model->initOf( 'update' )[ Arango::ARGS ] ) ;
    }

    public function testUpdateArgsAreVisibleAlongsideTheDocumentTheRelationsAndTheValue() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model , [
            ControllerParam::PAYLOAD => [ HttpMethod::ALL => [ 'tags' => [ Arango::TYPE => AQLType::EDGE ] ] ] ,
        ] ) ;

        $request = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 , 'tags' => 'roles/admin' ] ) ;

        $controller->patch( $request , null , [ 'id' => 'k1' , 'workspace' => 'w1' ] ) ;

        $init = $model->initOf( 'update' ) ;

        $this->assertSame( [ 'id' => 'k1' , 'workspace' => 'w1' ] , $init[ Arango::ARGS  ] ) ;
        $this->assertSame( 'k1'                                  , $init[ Arango::VALUE ] ) ;
        $this->assertArrayHasKey   ( 'tags' , $init[ Arango::RELATIONS ] ) ;
        $this->assertArrayNotHasKey( 'tags' , (array) $init[ Arango::DOC ] ) ;
    }

    public function testUpdatePassesTheRouteArgsToTheExistenceProbeToo() :void
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult  = 1 ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'a' => 1 ] ) ;

        $controller->patch( $request , null , [ 'id' => 'k1' , 'workspace' => 'w1' ] ) ;

        // aligned on delete() : the args are posed before the probe, so a host gating
        // existence on a route placeholder behaves the same on both verbs
        $this->assertSame( [ 'exist' , 'update' , 'get' ] , $model->methods() ) ;
        $this->assertSame( [ 'id' => 'k1' , 'workspace' => 'w1' ] , $model->initOf( 'exist' )[ Arango::ARGS ] ?? null ) ;
        $this->assertSame( 'k1' , $model->initOf( 'exist' )[ Arango::VALUE ] ) ;
    }
}
