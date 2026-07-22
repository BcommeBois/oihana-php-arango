<?php

namespace tests\oihana\arango\controllers;

use DI\Container;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\controllers\TraversalController;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use oihana\controllers\enums\ControllerParam;

use oihana\enums\Output;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use org\schema\constants\Schema;

use PHPUnit\Framework\Attributes\CoversClass;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use ReflectionException;
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

        $container->set( 'edge.service'         , $edges ) ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

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

    // ---- ?filter= on the traversed vertices -----------------------------

    public function testFilterIsCompiledAgainstTheVertexAndFoldedIntoTheTraversal() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound       = [] ;
        $edges->compiledFilter = 'vertex.status == @f0' ;
        $edges->compiledBinds  = [ 'f0' => 'published' ] ;

        $predicate = [ 'key' => 'status' , 'op' => 'eq' , 'val' => 'published' ] ;
        $request   = $this->makeRequest( [ ControllerParam::FILTER => json_encode( $predicate ) ] ) ;

        $this->makeController( $edges )->getChildren( $request , null , [ Schema::ID => '5' ] ) ;

        // The URL predicate is compiled against the traversed `vertex` variable.
        $this->assertCount( 1 , $edges->filterCalls ) ;
        $this->assertSame( $predicate  , $edges->filterCalls[ 0 ][ 0 ] ) ;
        $this->assertSame( AQL::VERTEX , $edges->filterCalls[ 0 ][ 1 ] ) ;

        // The compiled fragment + its binds are folded into the traversal init.
        $init = $edges->calls[ 0 ][ 2 ] ;
        $this->assertSame( 'vertex.status == @f0' , $init[ AQL::FILTER ] ?? null ) ;
        $this->assertSame( [ 'f0' => 'published' ] , $init[ AQL::BINDS  ] ?? null ) ;
    }

    public function testFilterAlsoAppliesToTheSingleParent() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->firstInbound   = (object) [ '_key' => 'root' ] ;
        $edges->compiledFilter = 'vertex.active == @f0' ;
        $edges->compiledBinds  = [ 'f0' => true ] ;

        $request = $this->makeRequest( [ ControllerParam::FILTER => json_encode( [ 'key' => 'active' , 'val' => true ] ) ] ) ;

        $this->makeController( $edges )->getParent( $request , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame( 'getFirstInboundVertex' , $edges->calls[ 0 ][ 0 ] ) ;
        $this->assertSame( AQL::VERTEX             , $edges->filterCalls[ 0 ][ 1 ] ) ;
        $this->assertSame( 'vertex.active == @f0'  , $edges->calls[ 0 ][ 2 ][ AQL::FILTER ] ?? null ) ;
    }

    public function testAnUndeclaredAttributeYieldsNoFilter() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound       = [] ;
        $edges->compiledFilter = null ; // the gated engine drops an undeclared attribute

        $request = $this->makeRequest( [ ControllerParam::FILTER => json_encode( [ 'key' => 'secret' , 'val' => 'x' ] ) ] ) ;

        $this->makeController( $edges )->getChildren( $request , null , [ Schema::ID => '5' ] ) ;

        $init = $edges->calls[ 0 ][ 2 ] ;
        $this->assertArrayNotHasKey( AQL::FILTER , $init ) ;
        $this->assertArrayNotHasKey( AQL::BINDS  , $init ) ;
    }

    public function testAnExplicitAuthorizerIsForwardedToTheEngineAndTheProjection() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound       = [] ;
        $edges->compiledFilter = 'vertex.status == @f0' ;
        $edges->compiledBinds  = [ 'f0' => 'x' ] ;

        $authorizer = fn( string $subject ) : bool => false ;

        $request = $this->makeRequest( [ ControllerParam::FILTER => json_encode( [ 'key' => 'status' , 'val' => 'x' ] ) ] ) ;

        $this->makeController( $edges )->getDescendants( $request , null , [ Schema::ID => '5' ] , [ Arango::AUTHORIZER => $authorizer ] ) ;

        // Forwarded to the filter engine (Field::REQUIRES gate on ?filter=) …
        $this->assertTrue( $edges->filterCalls[ 0 ][ 2 ] ) ;
        // … and into the traversal init (Field::REQUIRES gate on the vertex projection).
        $this->assertSame( $authorizer , $edges->calls[ 0 ][ 2 ][ Arango::AUTHORIZER ] ?? null ) ;
    }

    public function testMalformedFilterJsonNeverReachesTheEngine() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound = [] ;

        $request = $this->makeRequest( [ ControllerParam::FILTER => '{ not json' ] ) ;

        $this->makeController( $edges )->getChildren( $request , null , [ Schema::ID => '5' ] ) ;

        // Invalid JSON degrades to "no filter" : the engine is not even called.
        $this->assertSame( [] , $edges->filterCalls ) ;
        $this->assertArrayNotHasKey( AQL::FILTER , $edges->calls[ 0 ][ 2 ] ) ;
    }

    // ---- ?prune= (cut the branch under a non-matching vertex) -----------

    public function testPruneCompilesToFilterPlusNegatedPruneOnDescendants() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound       = [] ;
        $edges->compiledFilter = 'vertex.status == @f0' ;
        $edges->compiledBinds  = [ 'f0' => 'published' ] ;

        $request = $this->makeRequest( [ TraversalController::PRUNE_PARAM => json_encode( [ 'key' => 'status' , 'op' => 'eq' , 'val' => 'published' ] ) ] ) ;

        $this->makeController( $edges )->getDescendants( $request , null , [ Schema::ID => '5' ] ) ;

        $init = $edges->calls[ 0 ][ 2 ] ;
        // CAS A : the condition excludes the boundary (FILTER) and the negation cuts
        // its sub-tree (PRUNE), so a published node under a draft parent is unreachable.
        $this->assertSame( 'vertex.status == @f0'    , $init[ AQL::FILTER ] ?? null ) ;
        $this->assertSame( '!(vertex.status == @f0)' , $init[ AQL::PRUNE  ] ?? null ) ;
        $this->assertSame( [ 'f0' => 'published' ]   , $init[ AQL::BINDS  ] ?? null ) ;
    }

    public function testFilterAndPruneComposeIntoAnAndedFilter() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound            = [] ;
        // Distinct fragments for the ?filter= call then the ?prune= call.
        $edges->compiledFilterQueue = [ 'vertex.lang == @f0' , 'vertex.status == @f1' ] ;
        $edges->compiledBinds       = [ 'f0' => 'fr' ] ;

        $request = $this->makeRequest
        ([
            ControllerParam::FILTER      => json_encode( [ 'key' => 'lang'   , 'val' => 'fr'        ] ) ,
            TraversalController::PRUNE_PARAM => json_encode( [ 'key' => 'status' , 'val' => 'published' ] ) ,
        ]) ;

        $this->makeController( $edges )->getDescendants( $request , null , [ Schema::ID => '5' ] ) ;

        $init = $edges->calls[ 0 ][ 2 ] ;
        // Both conditions narrow the returned set (they AND in the FILTER) …
        $this->assertSame( [ 'vertex.lang == @f0' , 'vertex.status == @f1' ] , $init[ AQL::FILTER ] ?? null ) ;
        // … but only the prune one also stops the descent.
        $this->assertSame( '!(vertex.status == @f1)' , $init[ AQL::PRUNE ] ?? null ) ;
    }

    public function testPruneIsRejectedOnInboundAncestors() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;

        $request = $this->makeRequest( [ TraversalController::PRUNE_PARAM => json_encode( [ 'key' => 'status' , 'val' => 'published' ] ) ] ) ;

        // fail(400) with a null response returns null ; no traversal is attempted.
        $this->assertNull( $this->makeController( $edges )->getAncestors( $request , null , [ Schema::ID => '5' ] ) ) ;
        $this->assertSame( [] , $edges->calls ) ;
    }

    public function testPruneIsRejectedOnTheInboundSingleParent() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;

        $request = $this->makeRequest( [ TraversalController::PRUNE_PARAM => json_encode( [ 'key' => 'status' , 'val' => 'published' ] ) ] ) ;

        $this->assertNull( $this->makeController( $edges )->getParent( $request , null , [ Schema::ID => '5' ] ) ) ;
        $this->assertSame( [] , $edges->calls ) ;
    }

    public function testMalformedPruneJsonIsIgnored() :void
    {
        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound = [] ;

        $request = $this->makeRequest( [ TraversalController::PRUNE_PARAM => '{ not json' ] ) ;

        $this->makeController( $edges )->getDescendants( $request , null , [ Schema::ID => '5' ] ) ;

        // Invalid JSON degrades to "no prune" : the engine is not called, no PRUNE.
        $this->assertSame( [] , $edges->filterCalls ) ;
        $this->assertArrayNotHasKey( AQL::PRUNE , $edges->calls[ 0 ][ 2 ] ) ;
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

    /**
     * @return void
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ArangoException
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ConstantException
     */
    public function testManyEnvelopeCarriesCountAndTotal() :void
    {
        $children = [ [ '_key' => 'a' ] , [ '_key' => 'b' ] , [ '_key' => 'c' ] ] ;

        $edges = new RecordingTraversalEdges( 'has_subcategory' ) ;
        $edges->outbound = $children ;

        $result  = $this->makeController( $edges )->getChildren( $this->makeRequest() , $this->makeResponse() , [ Schema::ID => '5' ] ) ;

        $payload = json_decode( (string) $result->getBody() , true ) ;

        // Not paginated : count == total == the number of traversed vertices.
        $this->assertSame( 3 , $payload[ Output::COUNT ] ) ;
        $this->assertSame( 3 , $payload[ Output::TOTAL ] ) ;
    }
}
