<?php

namespace tests\oihana\arango\controllers;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\controllers\enums\ModelOperation;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\controllers\mocks\RecordingDocuments;
use tests\oihana\arango\controllers\mocks\RecordingHooksController;

/**
 * What a lifecycle hook is told about the call it is serving.
 *
 * The two hooks are shared by every verb, and one HTTP request runs several model
 * calls through them: a `PATCH` reaches `beforeModelCall()` three times, and
 * `getMethod()` answers `PATCH` to all three. Two of those three — the existence
 * probe and the read that hands the document back — even carry the same init keys,
 * so the shape of `$init` could not tell them apart either. These essays read the
 * announcement each call now carries.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( DocumentsController::class )]
#[CoversClass( ModelOperation::class )]
final class ModelOperationHookTest extends ControllerTestCase
{
    // ---- documents : the whole sequence of one request --------------------

    /**
     * ⭐ The essay this lot exists for. Three calls, three distinct announcements —
     * where a consumer used to read `PATCH` three times over.
     *
     * `afterModelCall()` sees two of the three: a probe answers a boolean and has no
     * result to hand back.
     */
    public function testAPatchAnnouncesItsThreeCallsInOrder() :void
    {
        $controller = $this->recordingController() ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'name' => 'Jane Doe' ] ) ;

        $controller->update( $request , null , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame( [ 'exist' , 'update' , 'get+afterWrite' ] , $controller->before ) ;
        $this->assertSame( [ 'update' , 'get+afterWrite' ]           , $controller->after  ) ;
    }

    /**
     * The same call site, the other verb: the operation is named after the model call,
     * which is exactly the distinction the HTTP verb cannot make from one site.
     */
    public function testAPutAnnouncesReplaceWhereAPatchAnnouncesUpdate() :void
    {
        $controller = $this->recordingController() ;
        $request    = $this->makeRequest( [] , 'PUT' )->withParsedBody( [ 'name' => 'Richard Roe' ] ) ;

        $controller->update( $request , null , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame( [ 'exist' , 'replace' , 'get+afterWrite' ] , $controller->before ) ;
    }

    /**
     * A `POST` is two calls, not one: the insertion, then the read that hands the
     * document back through the projection.
     */
    public function testAPostAnnouncesTheInsertThenTheReadThatFollowsIt() :void
    {
        $controller = $this->recordingController() ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'Alice Smith' ] ) ;

        $controller->post( $request , null , [] ) ;

        $this->assertSame( [ 'insert' , 'get+afterWrite' ] , $controller->before ) ;
        $this->assertSame( [ 'insert' , 'get+afterWrite' ] , $controller->after  ) ;
    }

    /**
     * One announcement, on purpose: the existence probe of a deletion runs under the
     * deletion's own init so the two cannot disagree on the scope a hook poses.
     */
    public function testADeleteAnnouncesOneOperationForItsProbeAndItsRemoval() :void
    {
        $controller = $this->recordingController() ;

        $controller->delete( $this->makeRequest( [] , 'DELETE' ) , null , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame( [ 'delete' ] , $controller->before ) ;
        $this->assertSame( [ 'delete' ] , $controller->after  ) ;
    }

    /**
     * The reads say what they are, and none of them carries the after-write flag —
     * only the read a controller runs on its own initiative does.
     */
    public function testEachReadAnnouncesItselfAndCarriesNoFlag() :void
    {
        foreach ( [ 'get' => 'get' , 'list' => 'list' , 'count' => 'count' , 'last' => 'last' ] as $verb => $expected )
        {
            $controller = $this->recordingController() ;

            $controller->{ $verb }( $this->makeRequest() , null , [ Arango::ID => 'k1' ] ) ;

            $this->assertSame( [ $expected ] , $controller->before , "the $verb hook announcement" ) ;
        }
    }

    // ---- the announcement travels with the init ---------------------------

    /**
     * The key is posed at the construction of the init, not just before the hook, so
     * it reaches the model too — and, above all, the second hook: the array is built
     * once and handed to both by reference. Posed later, `afterModelCall()` would be
     * back to guessing, which is half of what this lot is about.
     */
    public function testTheAnnouncementReachesTheModelItself() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'Jane Doe' ] ) ;

        $controller->post( $request , null , [] ) ;

        $this->assertSame( ModelOperation::INSERT , $model->initOf( 'insert' )[ Arango::OPERATION ] ?? null ) ;
        $this->assertSame( ModelOperation::GET    , $model->initOf( 'get'    )[ Arango::OPERATION ] ?? null ) ;
        $this->assertTrue( $model->initOf( 'get' )[ Arango::AFTER_WRITE ] ?? false ) ;
    }

    /**
     * An operation carried by the caller's own init must not survive into the
     * announcement: every call site poses its key **after** the spread that copies
     * the caller init.
     */
    public function testACallerCannotDictateTheAnnouncement() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'name' => 'Jane Doe' ] ) ;

        $controller->update( $request , null , [ Arango::ID => 'k1' ] , [ Arango::OPERATION => ModelOperation::LIST ] ) ;

        $this->assertSame( ModelOperation::UPDATE , $model->initOf( 'update' )[ Arango::OPERATION ] ?? null ) ;
        $this->assertSame( ModelOperation::EXIST  , $model->initOf( 'exist'  )[ Arango::OPERATION ] ?? null ) ;
    }

    /**
     * Purely additive: a consumer who never heard of the key gets what it always got.
     * No signature moved, and the plain controller answers the same document.
     */
    public function testAControllerThatIgnoresTheKeyBehavesExactlyAsBefore() :void
    {
        $model = $this->recordingModel() ;

        $controller = $this->makeDocumentsController( $model ) ;
        $request    = $this->makeRequest( [] , 'POST' )->withParsedBody( [ 'name' => 'Alice Smith' ] ) ;

        $this->assertSame( $model->objectResult , $controller->post( $request , null , [] ) ) ;
    }

    // ---- harness ---------------------------------------------------------

    /**
     * A model double that answers every call of a write sequence.
     */
    private function recordingModel() :RecordingDocuments
    {
        $model = new RecordingDocuments( 'users' ) ;

        $model->firstResult  = 1 ; // exist() → true
        $model->objectResult = (object) [ '_key' => 'k1' , 'name' => 'Jane Doe' ] ;

        return $model ;
    }

    /**
     * The consumer subclass writing down what each hook call announced.
     */
    private function recordingController() :RecordingHooksController
    {
        return new RecordingHooksController( ...$this->controllerArgsOf( $this->recordingModel() ) ) ;
    }
}
