<?php

namespace tests\oihana\arango\controllers;

use DI\Container;

use oihana\arango\controllers\TraversalController;
use oihana\arango\db\enums\AQL;

use oihana\controllers\enums\ControllerParam;

use oihana\enums\Output;

use org\schema\constants\Schema;

use PHPUnit\Framework\Attributes\CoversClass;

use Slim\Factory\AppFactory;

use tests\oihana\arango\controllers\mocks\RecordingTraversalEdges;

/**
 * Coverage for {@see TraversalController} — the generic self-referential edge
 * navigator. Handlers are called with a null response, so `success()` returns
 * the raw payload and `fail()` returns null.
 *
 * The edge is a hand-written {@see RecordingTraversalEdges} double that records
 * the traversal calls, so we assert both the returned payload and the direction
 * / transitivity / depth each method drives.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( TraversalController::class )]
final class TraversalControllerTest extends ControllerTestCase
{
    private function makeController( RecordingTraversalEdges $edges ) :TraversalController
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $container->set( 'edge.service' , $edges ) ;

        return new TraversalController( $container ,
        [
            ControllerParam::APP      => $app ,
            ControllerParam::ROUTER   => $app->getRouteCollector()->getRouteParser() ,
            TraversalController::EDGE => 'edge.service' ,
        ]) ;
    }

    /**
     * A controller wired without the {@see TraversalController::EDGE} model, so
     * `$this->edges` stays null and the "not configured" guards fire.
     */
    private function makeControllerWithoutEdge() :TraversalController
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        return new TraversalController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
        ]) ;
    }

    // ---- parent (INBOUND, single) ---------------------------------------

    public function testGetParentReturnsTheInboundVertex() :void
    {
        $parent = (object) [ '_key' => 'root' , 'name' => 'Root' ] ;

        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->firstInbound = $parent ;

        $result = $this->makeController( $edges )->getParent( null , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame( $parent , $result ) ;
        $this->assertSame( [ [ 'getFirstInboundVertex' , '5' , [] ] ] , $edges->calls ) ;
    }

    public function testGetParentReturnsNullForARoot() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->firstInbound = null ;

        $this->assertNull( $this->makeController( $edges )->getParent( null , null , [ Schema::ID => '5' ] ) ) ;
    }

    // ---- children (OUTBOUND, direct — no depth) -------------------------

    public function testGetChildrenIsOutboundWithoutDepth() :void
    {
        $children = [ [ '_key' => 'a' ] , [ '_key' => 'b' ] ] ;

        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound = $children ;

        $result = $this->makeController( $edges )->getChildren( null , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame( $children , $result ) ;
        $this->assertSame( [ [ 'getOutboundVertices' , '5' , [] ] ] , $edges->calls ) ;
    }

    // ---- ancestors (INBOUND, transitive) --------------------------------

    public function testGetAncestorsIsInboundAndTransitive() :void
    {
        $ancestors = [ [ '_key' => 'p' ] , [ '_key' => 'root' ] ] ;

        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->inbound = $ancestors ;

        $result = $this->makeController( $edges )->getAncestors( null , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame( $ancestors , $result ) ;
        $this->assertSame
        (
            [ [ 'getInboundVertices' , '5' , [ AQL::MIN_DEPTH => 1 , AQL::MAX_DEPTH => TraversalController::DEFAULT_MAX_DEPTH ] ] ] ,
            $edges->calls
        ) ;
    }

    // ---- descendants (OUTBOUND, transitive + ?depth cap) ----------------

    public function testGetDescendantsIsOutboundAndTransitive() :void
    {
        $descendants = [ [ '_key' => 'a' ] , [ '_key' => 'a1' ] ] ;

        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound = $descendants ;

        $result = $this->makeController( $edges )->getDescendants( null , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame( $descendants , $result ) ;
        $this->assertSame
        (
            [ [ 'getOutboundVertices' , '5' , [ AQL::MIN_DEPTH => 1 , AQL::MAX_DEPTH => TraversalController::DEFAULT_MAX_DEPTH ] ] ] ,
            $edges->calls
        ) ;
    }

    public function testGetDescendantsClampsTheDepthQueryParam() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound = [] ;

        $request = $this->makeRequest( [ TraversalController::DEPTH_PARAM => '2' ] ) ;

        $this->assertSame( [] , $this->makeController( $edges )->getDescendants( $request , null , [ Schema::ID => '5' ] ) ) ;
        $this->assertSame
        (
            [ [ 'getOutboundVertices' , '5' , [ AQL::MIN_DEPTH => 1 , AQL::MAX_DEPTH => 2 ] ] ] ,
            $edges->calls
        ) ;
    }

    // ---- guards ---------------------------------------------------------

    public function testMissingIdReturnsNull() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;

        // fail() with a null response returns null ; no traversal is attempted.
        $this->assertNull( $this->makeController( $edges )->getChildren( null , null , [] ) ) ;
        $this->assertSame( [] , $edges->calls ) ;
    }

    public function testSingleMissingIdReturnsNull() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;

        // single() with a missing id fails before any traversal.
        $this->assertNull( $this->makeController( $edges )->getParent( null , null , [] ) ) ;
        $this->assertSame( [] , $edges->calls ) ;
    }

    public function testManyWithoutEdgeReturnsNull() :void
    {
        $this->assertNull( $this->makeControllerWithoutEdge()->getChildren( null , null , [ Schema::ID => '5' ] ) ) ;
    }

    public function testSingleWithoutEdgeReturnsNull() :void
    {
        $this->assertNull( $this->makeControllerWithoutEdge()->getParent( null , null , [ Schema::ID => '5' ] ) ) ;
    }

    // ---- response envelope ----------------------------------------------

    public function testManyEnvelopeCarriesCountAndTotal() :void
    {
        $children = [ [ '_key' => 'a' ] , [ '_key' => 'b' ] , [ '_key' => 'c' ] ] ;

        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound = $children ;

        $result  = $this->makeController( $edges )
            ->getChildren( $this->makeRequest() , $this->makeResponse() , [ Schema::ID => '5' ] ) ;

        $payload = json_decode( (string) $result->getBody() , true ) ;

        // Not paginated : count == total == the number of traversed vertices.
        $this->assertSame( 3 , $payload[ Output::COUNT ] ) ;
        $this->assertSame( 3 , $payload[ Output::TOTAL ] ) ;
    }
}
