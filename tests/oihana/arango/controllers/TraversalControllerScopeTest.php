<?php

namespace tests\oihana\arango\controllers;

use Closure;
use ReflectionMethod;

use DI\Container;

use PHPUnit\Framework\Attributes\CoversClass;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use Slim\Factory\AppFactory;

use oihana\arango\controllers\TraversalController;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use oihana\auth\CapabilityEnforcerInterface;
use oihana\auth\PermissionSubjectResolverInterface;

use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\RequestAttribute;

use org\schema\constants\Schema;

use tests\oihana\arango\controllers\mocks\CompilingTraversalController;
use tests\oihana\arango\controllers\mocks\RecordingTraversalEdges;
use tests\oihana\arango\controllers\mocks\ScopedTraversalController;

use function oihana\arango\db\operations\aqlFilter;

/**
 * Coverage for the **authorization seat** of {@see TraversalController} : the four
 * traversals wrapped by the {@see \oihana\controllers\traits\ModelCallTrait} hooks,
 * and the fact that a consumer subclass can impose a scope the caller cannot widen
 * through `?filter=` or `?prune=`.
 *
 * The payload differs from the other surfaces and the tests say so: at hook time
 * `AQL::FILTER` holds **compiled AQL fragments** targeting `vertex`, not a JSON
 * predicate — `getVertices()` reads that slot and never `Arango::CONDITIONS`. Both
 * ways in are covered: a hand-written fragment ({@see ScopedTraversalController})
 * and one compiled through the gated engine ({@see CompilingTraversalController}).
 *
 * The lib provides the seat, never the rule: nothing here names a business concept.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( TraversalController::class )]
final class TraversalControllerScopeTest extends ControllerTestCase
{
    // ---- the scope alone -------------------------------------------------

    /**
     * The four public verbs go through `many()` or `single()`, so both must carry
     * the scope — a `/parent` answering outside it would be the hole.
     */
    public function testTheScopeReachesTheFourTraversals() :void
    {
        $verbs =
        [
            'getParent'      => 'getFirstInboundVertex' ,
            'getChildren'    => 'getOutboundVertices'   ,
            'getAncestors'   => 'getInboundVertices'    ,
            'getDescendants' => 'getOutboundVertices'   ,
        ] ;

        foreach ( $verbs as $verb => $expected )
        {
            $edges      = $this->edges() ;
            $controller = $this->makeScopedController( $edges ) ;

            $controller->{ $verb }( $this->makeRequest() , null , [ Schema::ID => '5' ] ) ;

            $this->assertSame( $expected , $edges->calls[ 0 ][ 0 ] ?? null , $verb . ' called the wrong traversal' ) ;

            $this->assertSame
            (
                [ ScopedTraversalController::FRAGMENT ] ,
                $edges->calls[ 0 ][ 2 ][ AQL::FILTER ] ?? null ,
                $verb . ' lost the scope'
            ) ;

            $this->assertSame( ScopedTraversalController::BINDS , $edges->calls[ 0 ][ 2 ][ AQL::BINDS ] ?? null ) ;
        }
    }

    // ---- the scope vs the client levers ----------------------------------

    /**
     * The hook runs after `prepareVertexFilter()`, so the client fragment is
     * already in the list when the scope is appended : two operands, ANDed.
     */
    public function testTheScopeIsAppendedAfterTheClientFilter() :void
    {
        $edges = $this->edges() ;
        $edges->compiledFilter = 'vertex.lang == @f0' ;
        $edges->compiledBinds  = [ 'f0' => 'fr' ] ;

        $request = $this->makeRequest( [ ControllerParam::FILTER => json_encode( [ 'key' => 'lang' , 'val' => 'fr' ] ) ] ) ;

        $this->makeScopedController( $edges )->getChildren( $request , null , [ Schema::ID => '5' ] ) ;

        $init = $edges->calls[ 0 ][ 2 ] ;

        $this->assertSame( [ 'vertex.lang == @f0' , ScopedTraversalController::FRAGMENT ] , $init[ AQL::FILTER ] ?? null ) ;
        $this->assertSame( [ 'f0' => 'fr' , '__scope' => 'visible' ]                      , $init[ AQL::BINDS  ] ?? null ) ;
    }

    /**
     * The shape that would break the invariant : a disjunctive client filter. A
     * compiled group carries its own parentheses (`prepareFilterConditions()`
     * renders with `useParentheses: true`) and the fragments are joined by `&&`,
     * so the rendered clause is `( a || b ) && scope` — never `scope && a || b`,
     * which AQL would read as `( scope && a ) || b` and hand back everything `b`
     * matches, outside the scope.
     *
     * Asserted on the AQL the real `aqlFilter()` renders, not on the array shape :
     * the shape is the means, the clause is the guarantee.
     */
    public function testAClientOrGroupCannotDegradeTheScope() :void
    {
        $edges = $this->edges() ;
        $edges->compiledFilter = '(vertex.a == @f0 || vertex.b == @f1)' ;

        $request = $this->makeRequest( [ ControllerParam::FILTER => json_encode( [ 'or' , [ 'key' => 'a' ] , [ 'key' => 'b' ] ] ) ] ) ;

        $this->makeScopedController( $edges )->getDescendants( $request , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame
        (
            'FILTER (vertex.a == @f0 || vertex.b == @f1) && vertex.__scope == @__scope' ,
            aqlFilter( $edges->calls[ 0 ][ 2 ][ AQL::FILTER ] ?? null )
        ) ;
    }

    /**
     * `?prune=` cuts the descent ; the scope only narrows the result. The scope
     * therefore joins the `FILTER` and must **not** join the `PRUNE` — pruning on
     * a server-side scope would stop the walk at the first out-of-scope vertex and
     * hide its in-scope descendants.
     */
    public function testTheScopeJoinsTheFilterButNotThePrune() :void
    {
        $edges = $this->edges() ;
        $edges->compiledFilter = 'vertex.status == @f0' ;

        $request = $this->makeRequest( [ TraversalController::PRUNE_PARAM => json_encode( [ 'key' => 'status' , 'val' => 'published' ] ) ] ) ;

        $this->makeScopedController( $edges )->getDescendants( $request , null , [ Schema::ID => '5' ] ) ;

        $init = $edges->calls[ 0 ][ 2 ] ;

        $this->assertSame( [ 'vertex.status == @f0' , ScopedTraversalController::FRAGMENT ] , $init[ AQL::FILTER ] ?? null ) ;
        $this->assertSame( '!(vertex.status == @f0)'                                        , $init[ AQL::PRUNE  ] ?? null ) ;
    }

    // ---- the result ------------------------------------------------------

    public function testAfterModelCallReceivesTheVerticesAndCanReplaceThem() :void
    {
        $edges = $this->edges() ;
        $edges->outbound = [ [ '_key' => 'stored' ] ] ;

        $controller = $this->makeScopedController( $edges ) ;

        $result = $controller->getChildren( $this->makeRequest() , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame( [ [ '_key' => 'stored' ] ]              , $controller->seen ) ;
        $this->assertSame( ScopedTraversalController::REPLACEMENT  , $result ) ;
    }

    public function testAfterModelCallReceivesTheSingleVertex() :void
    {
        $vertex = (object) [ '_key' => 'root' ] ;

        $edges = $this->edges() ;
        $edges->firstInbound = $vertex ;

        $controller = $this->makeScopedController( $edges ) ;

        $result = $controller->getParent( $this->makeRequest() , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame( $vertex                                , $controller->seen ) ;
        $this->assertSame( ScopedTraversalController::REPLACEMENT , $result ) ;
    }

    // ---- compiling a scope through the gated engine ----------------------

    /**
     * `compileVertexPredicate()` is protected so a scope can go through the same
     * whitelist and `Field::REQUIRES` gate as the client filter, instead of being
     * hand-written AQL. Its binds merge into the ones already collected.
     */
    public function testACompiledScopeGoesThroughTheGatedEngineAndMergesItsBinds() :void
    {
        $edges = $this->edges() ;
        $edges->compiledFilterQueue = [ 'vertex.lang == @f0' , 'vertex.__scope == @f1' ] ;
        $edges->compiledBinds       = [ 'f1' => 'visible' ] ;

        $request = $this->makeRequest( [ ControllerParam::FILTER => json_encode( [ 'key' => 'lang' , 'val' => 'fr' ] ) ] ) ;

        $this->makeCompilingController( $edges )->getChildren( $request , null , [ Schema::ID => '5' ] ) ;

        // Two compilations : the client's, then the scope's — both against `vertex`.
        $this->assertCount( 2 , $edges->filterCalls ) ;
        $this->assertSame( CompilingTraversalController::PREDICATE , $edges->filterCalls[ 1 ][ 0 ] ) ;
        $this->assertSame( AQL::VERTEX                             , $edges->filterCalls[ 1 ][ 1 ] ) ;

        $init = $edges->calls[ 0 ][ 2 ] ;

        $this->assertSame( [ 'vertex.lang == @f0' , 'vertex.__scope == @f1' ] , $init[ AQL::FILTER ] ?? null ) ;
        $this->assertSame( [ 'f1' => 'visible' ]                              , $init[ AQL::BINDS  ] ?? null ) ;
    }

    /**
     * A `null` compilation means the attribute is not declared filterable. Silently
     * reading that as "no scope" would let a mis-declared scope evaporate, so the
     * consumer double reports it — and poses nothing.
     */
    public function testACompilationRefusalIsNotAnAbsentScope() :void
    {
        $edges = $this->edges() ;
        $edges->compiledFilter = null ; // nothing filterable

        $controller = $this->makeCompilingController( $edges ) ;

        $controller->getChildren( $this->makeRequest() , null , [ Schema::ID => '5' ] ) ;

        $this->assertTrue( $controller->refused ) ;
        $this->assertArrayNotHasKey( AQL::FILTER , $edges->calls[ 0 ][ 2 ] ) ;
    }

    // ---- the authorizer --------------------------------------------------

    /**
     * The authorizer is posed by `prepareVertexFilter()` — it gates the compilation
     * of `?filter=`, so it cannot wait for the hook. An override reading
     * `Arango::AUTHORIZER` therefore finds it, which is what lets a scope be
     * compiled under the caller's own permissions.
     */
    public function testTheAuthorizerIsAlreadyThereWhenTheHookRuns() :void
    {
        $edges      = $this->edges() ;
        $controller = $this->makeScopedController( $edges , withStack: true ) ;
        $request    = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $controller->getChildren( $request , null , [ Schema::ID => '5' ] ) ;

        $this->assertInstanceOf( Closure::class , $edges->calls[ 0 ][ 2 ][ Arango::AUTHORIZER ] ?? null ) ;
    }

    public function testACallerAuthorizerStillWins() :void
    {
        $edges    = $this->edges() ;
        $sentinel = static fn( string $subject ) :bool => true ;

        $this->makeScopedController( $edges , withStack: true )
             ->getChildren( $this->makeRequest() , null , [ Schema::ID => '5' ] , [ Arango::AUTHORIZER => $sentinel ] ) ;

        $this->assertSame( $sentinel , $edges->calls[ 0 ][ 2 ][ Arango::AUTHORIZER ] ?? null ) ;
    }

    // ---- non-regression --------------------------------------------------

    /**
     * A plain controller, no subclass and no authorization stack: the traversal
     * init is exactly what it was before the seat existed — empty on a direct
     * traversal with no client lever.
     */
    public function testAPlainControllerSendsAnUntouchedInit() :void
    {
        $edges = $this->edges() ;

        $this->makeController( $edges )->getChildren( $this->makeRequest() , null , [ Schema::ID => '5' ] ) ;

        $this->assertSame( [ [ 'getOutboundVertices' , '5' , [] ] ] , $edges->calls ) ;
    }

    public function testTheDefaultHooksAreNoOps() :void
    {
        $controller = $this->makeController( $this->edges() ) ;

        $init   = [ AQL::FILTER => [ 'vertex.a == @f0' ] ] ;
        $result = [ 'untouched' ] ;

        $before = new ReflectionMethod( $controller , 'beforeModelCall' ) ;
        $after  = new ReflectionMethod( $controller , 'afterModelCall'  ) ;

        $before->invokeArgs( $controller , [ $this->makeRequest() , &$init ] ) ;
        $after ->invokeArgs( $controller , [ $this->makeRequest() , &$init , &$result ] ) ;

        $this->assertSame( [ AQL::FILTER => [ 'vertex.a == @f0' ] ] , $init ) ;
        $this->assertSame( [ 'untouched' ]                          , $result ) ;
    }

    // ---- harness ---------------------------------------------------------

    /** The constructor arguments of a controller over the given edge double. */
    private function controllerArgsFor( RecordingTraversalEdges $edges , bool $withStack = false ) :array
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $container->set( 'edge.service'         , $edges ) ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        if ( $withStack )
        {
            $container->set( CapabilityEnforcerInterface::class        , $this->createStub( CapabilityEnforcerInterface::class ) ) ;
            $container->set( PermissionSubjectResolverInterface::class , $this->createStub( PermissionSubjectResolverInterface::class ) ) ;
        }

        return
        [
            $container ,
            [
                ControllerParam::APP      => $app ,
                ControllerParam::ROUTER   => $app->getRouteCollector()->getRouteParser() ,
                TraversalController::EDGE => 'edge.service' ,
            ]
        ] ;
    }

    /** A self-referential edge double whose traversals return nothing by default. */
    private function edges() :RecordingTraversalEdges
    {
        return new RecordingTraversalEdges( 'has_subcategory' ) ;
    }

    /** A consumer subclass compiling its scope through the gated engine. */
    private function makeCompilingController( RecordingTraversalEdges $edges ) :CompilingTraversalController
    {
        return new CompilingTraversalController( ...$this->controllerArgsFor( $edges ) ) ;
    }

    /** A plain controller over the given edge double. */
    private function makeController( RecordingTraversalEdges $edges ) :TraversalController
    {
        return new TraversalController( ...$this->controllerArgsFor( $edges ) ) ;
    }

    /** A consumer subclass posing a hand-written fragment. */
    private function makeScopedController( RecordingTraversalEdges $edges , bool $withStack = false ) :ScopedTraversalController
    {
        return new ScopedTraversalController( ...$this->controllerArgsFor( $edges , $withStack ) ) ;
    }
}
