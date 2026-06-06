<?php

namespace tests\oihana\arango\controllers;

use oihana\arango\controllers\EdgesController;
use oihana\enums\http\HttpStatusCode;

use org\schema\constants\Schema;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\controllers\mocks\ThrowingEdges;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Coverage for {@see EdgesController} — the generic two-vertex edge controller
 * (post / delete). Driven with a real Slim app + container; handlers called
 * with null response so `success()` returns raw data and `fail()` returns null.
 *
 * Vertex ids are passed as full `_id`s so the Edges model resolves them without
 * wired from/to vertex models.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( EdgesController::class )]
class EdgesControllerTest extends ControllerTestCase
{
    // ---- post -----------------------------------------------------------

    public function testPostCreatesEdgeWithoutVertexModels() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;
        $edges->firstResult  = 0 ; // existEdge() inside insertEdge() → not a duplicate
        $edges->objectResult = (object) [ '_key' => 'e1' , '_from' => 'users/u1' , '_to' => 'roles/r1' ] ;

        $controller = $this->makeEdgesController( $edges ) ;

        $result = $controller->post( null , null , [ Schema::ID => 'users/u1' , EdgesController::TARGET_ID => 'roles/r1' ] ) ;

        $this->assertSame( $edges->objectResult , $result ) ;
    }

    public function testPostValidatesSourceAndTargetVerticesWhenModelsWired() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;
        $edges->firstResult  = 0 ;
        $edges->objectResult = (object) [ '_key' => 'e1' ] ;

        $from = new MockDocuments( 'users' ) ;
        $from->firstResult = 1 ; // source exists
        $to = new MockDocuments( 'roles' ) ;
        $to->firstResult = 1 ;   // target exists

        $controller = $this->makeEdgesController( $edges , $from , $to ) ;

        // full _ids so the unwired Edges model resolves the vertices for insertEdge
        $result = $controller->post( null , null , [ Schema::ID => 'users/u1' , EdgesController::TARGET_ID => 'roles/r1' ] ) ;

        $this->assertSame( $edges->objectResult , $result ) ;
    }

    public function testPostReturnsBadRequestWhenIdsMissing() :void
    {
        $controller = $this->makeEdgesController( new MockEdges( 'user_has_roles' ) ) ;

        $this->assertNull( $controller->post( null , null , [ Schema::ID => 'u1' ] ) ) ; // no targetId
    }

    public function testPostReturnsNotFoundWhenSourceMissing() :void
    {
        $from = new MockDocuments( 'users' ) ;
        $from->firstResult = 0 ; // source does not exist

        $controller = $this->makeEdgesController( new MockEdges( 'user_has_roles' ) , $from ) ;

        $this->assertNull( $controller->post( null , null , [ Schema::ID => 'u1' , EdgesController::TARGET_ID => 'r1' ] ) ) ;
    }

    public function testPostReturnsNotFoundWhenTargetMissing() :void
    {
        $from = new MockDocuments( 'users' ) ;
        $from->firstResult = 1 ; // source exists
        $to = new MockDocuments( 'roles' ) ;
        $to->firstResult = 0 ;   // target missing

        $controller = $this->makeEdgesController( new MockEdges( 'user_has_roles' ) , $from , $to ) ;

        $this->assertNull( $controller->post( null , null , [ Schema::ID => 'u1' , EdgesController::TARGET_ID => 'r1' ] ) ) ;
    }

    public function testPostReturnsConflictWhenEdgeAlreadyExists() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;
        $edges->firstResult = 1 ; // existEdge() → duplicate → insertEdge throws Error409

        // a real response so fail() returns a 409 Response to assert on
        $controller = $this->makeEdgesController( $edges ) ;

        $result = $controller->post
        (
            null ,
            $this->makeResponse() ,
            [ Schema::ID => 'users/u1' , EdgesController::TARGET_ID => 'roles/r1' ]
        ) ;

        $this->assertSame( HttpStatusCode::CONFLICT , $result->getStatusCode() ) ;
    }

    public function testPostReturnsNullOnGenericFailure() :void
    {
        $controller = $this->makeEdgesController( new ThrowingEdges( 'user_has_roles' ) ) ;

        $this->assertNull( $controller->post( null , null , [ Schema::ID => 'users/u1' , EdgesController::TARGET_ID => 'roles/r1' ] ) ) ;
    }

    // ---- delete ---------------------------------------------------------

    public function testDeleteRemovesEdge() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;
        $edges->firstResult     = 1 ; // existEdge() → true
        $edges->documentsResult = [ (object) [ '_key' => 'e1' ] ] ; // deleteEdge() returns the removed edge

        $controller = $this->makeEdgesController( $edges ) ;

        $result = $controller->delete( null , null , [ Schema::ID => 'users/u1' , EdgesController::TARGET_ID => 'roles/r1' ] ) ;

        $this->assertSame( 'e1' , $result->_key ) ;
    }

    public function testDeleteReturnsBadRequestWhenIdsMissing() :void
    {
        $controller = $this->makeEdgesController( new MockEdges( 'user_has_roles' ) ) ;

        $this->assertNull( $controller->delete( null , null , [] ) ) ;
    }

    public function testDeleteReturnsNotFoundWhenSourceMissing() :void
    {
        $from = new MockDocuments( 'users' ) ;
        $from->firstResult = 0 ;

        $controller = $this->makeEdgesController( new MockEdges( 'user_has_roles' ) , $from ) ;

        $this->assertNull( $controller->delete( null , null , [ Schema::ID => 'u1' , EdgesController::TARGET_ID => 'r1' ] ) ) ;
    }

    public function testDeleteReturnsNotFoundWhenEdgeMissing() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;
        $edges->firstResult = 0 ; // existEdge() → false

        $controller = $this->makeEdgesController( $edges ) ;

        $this->assertNull( $controller->delete( null , null , [ Schema::ID => 'users/u1' , EdgesController::TARGET_ID => 'roles/r1' ] ) ) ;
    }

    public function testDeleteReturnsNullOnGenericFailure() :void
    {
        $controller = $this->makeEdgesController( new ThrowingEdges( 'user_has_roles' ) ) ;

        $this->assertNull( $controller->delete( null , null , [ Schema::ID => 'users/u1' , EdgesController::TARGET_ID => 'roles/r1' ] ) ) ;
    }
}
