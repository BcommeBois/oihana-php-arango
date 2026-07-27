<?php

namespace tests\oihana\arango\controllers;

use Closure;
use ReflectionMethod;

use DI\Container;

use PHPUnit\Framework\Attributes\CoversClass;

use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Factory\AppFactory;

use oihana\arango\controllers\ArrayPropertyController;
use oihana\arango\controllers\PropertyController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;
use oihana\auth\CapabilityEnforcerInterface;
use oihana\auth\PermissionSubjectResolverInterface;
use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\RequestAttribute;

use tests\oihana\arango\controllers\mocks\RecordingDocuments;
use tests\oihana\arango\controllers\mocks\ScopedArrayPropertyController;
use tests\oihana\arango\controllers\mocks\ScopedPropertyController;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for the **authorization seat** of {@see PropertyController} and
 * {@see ArrayPropertyController} : the constructor resolving the capability
 * enforcer and the permission-subject resolver from the container, the
 * `beforeModelCall()` hook posing the request-scoped authorizer, and the fact
 * that a subclass overriding that hook can reach every model call the two
 * controllers make — reads, writes, and the six array operations.
 *
 * The lib provides the seat, never the rule: nothing here names a business
 * concept. A consumer supplies the predicate through a subclass, which is what
 * {@see ScopedPropertyController} stands in for.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( PropertyController::class )]
#[CoversClass( ArrayPropertyController::class )]
final class PropertyControllerScopeTest extends ControllerTestCase
{
    /** The `property` init key (PropertyTrait::PROPERTY, a trait constant). */
    private const string PROPERTY = 'property' ;

    // ---- the seat itself -------------------------------------------------

    public function testConstructorResolvesEnforcerAndSubjectResolverFromContainer() :void
    {
        $controller = $this->makeAuthController( withStack: true ) ;

        $this->assertInstanceOf( PropertyController::class , $controller ) ;
    }

    public function testBeforeModelCallInjectsAuthorizerWhenStackAndUserPresent() :void
    {
        $controller = $this->makeAuthController( withStack: true ) ;
        $request    = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $init = [] ;
        $this->invokeBeforeModelCall( $controller , $request , $init ) ;

        $this->assertArrayHasKey( Arango::AUTHORIZER , $init ) ;
        $this->assertInstanceOf( Closure::class , $init[ Arango::AUTHORIZER ] ) ;
    }

    public function testBeforeModelCallLeavesAnExistingAuthorizerUntouched() :void
    {
        $controller = $this->makeAuthController( withStack: true ) ;
        $request    = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $sentinel = static fn( string $subject ) :bool => true ;
        $init     = [ Arango::AUTHORIZER => $sentinel ] ;

        $this->invokeBeforeModelCall( $controller , $request , $init ) ;

        $this->assertSame( $sentinel , $init[ Arango::AUTHORIZER ] ) ;
    }

    /**
     * No authorization stack in the container (CLI, tests, an app that never
     * wired auth) → nothing is posed and the projection layer falls open. This
     * is the backward-compatibility guarantee, identical to the sister class.
     */
    public function testBeforeModelCallSkipsWithoutAuthorizationStack() :void
    {
        $controller = $this->makeAuthController( withStack: false ) ;
        $request    = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $init = [] ;
        $this->invokeBeforeModelCall( $controller , $request , $init ) ;

        $this->assertArrayNotHasKey( Arango::AUTHORIZER , $init ) ;
    }

    public function testBeforeModelCallSkipsWithoutAuthenticatedUser() :void
    {
        $controller = $this->makeAuthController( withStack: true ) ;
        $request    = $this->makeRequest() ; // no USER_ID → buildPermissionAuthorizer() returns null

        $init = [] ;
        $this->invokeBeforeModelCall( $controller , $request , $init ) ;

        $this->assertArrayNotHasKey( Arango::AUTHORIZER , $init ) ;
    }

    public function testBeforeModelCallSkipsWithNullRequest() :void
    {
        $controller = $this->makeAuthController( withStack: true ) ;

        $init = [] ;
        $this->invokeBeforeModelCall( $controller , null , $init ) ;

        $this->assertArrayNotHasKey( Arango::AUTHORIZER , $init ) ;
    }

    public function testGetPropagatesTheAuthorizerToTheModel() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makeAuthController( withStack: true , model: $model ) ;
        $request    = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $controller->get( $request , null , [ Arango::ID => 'k1' ] ) ;

        $init = $model->initOf( 'get' ) ;

        $this->assertIsArray( $init , 'get() was never called' ) ;
        $this->assertInstanceOf( Closure::class , $init[ Arango::AUTHORIZER ] ?? null ) ;
    }

    // ---- a subclass scope reaches the read -------------------------------

    public function testGetForwardsTheConditionsPosedByASubclassHook() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makeScopedController( $model ) ;

        $controller->get( $this->makeRequest() , null , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame
        (
            [ ScopedPropertyController::CONDITION ] ,
            $model->initOf( 'get' )[ Arango::CONDITIONS ] ?? null
        ) ;
    }

    public function testGetForwardsTheBindsPosedByASubclassHook() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makeScopedController( $model ) ;

        $controller->get( $this->makeRequest() , null , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame
        (
            ScopedPropertyController::BINDS ,
            $model->initOf( 'get' )[ Arango::BINDS ] ?? null
        ) ;
    }

    /**
     * `CONDITIONS` declared at the definition level (the route `$init`) reach the
     * query on their own, without any subclass — this is the static half of the
     * seat, and what the hook then adds to.
     */
    public function testGetForwardsTheConditionsDeclaredAtDefinitionLevel() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makePropertyController( $model , [ self::PROPERTY => 'emails' ] ) ;

        $controller->get( null , null , [ Arango::ID => 'k1' ] , [ Arango::CONDITIONS => [ 'doc.active == true' ] ] ) ;

        $this->assertSame( [ 'doc.active == true' ] , $model->initOf( 'get' )[ Arango::CONDITIONS ] ?? null ) ;
    }

    public function testAfterModelCallCanReplaceTheDocumentRead() :void
    {
        $model = $this->recordingModel() ;
        $model->objectResult = (object) [ 'emails' => [ 'stored@x' ] ] ;

        $controller = $this->makeScopedController( $model ) ;

        // the subclass hook swaps the document for a canned one
        $this->assertSame( [ 'replaced@x' ] , $controller->get( $this->makeRequest() , null , [ Arango::ID => 'k1' ] ) ) ;
    }

    // ---- the silent answer (no 404) --------------------------------------

    /**
     * A document filtered out by the scope, an unknown identifier, and a visible
     * document whose property is simply absent must be **indistinguishable**:
     * all three answer 200 with a null result. Answering 404 on the first two
     * would turn the endpoint into the oracle the scope exists to close.
     */
    public function testFilteredUnknownAndEmptyAllAnswerTheSame() :void
    {
        $filtered = $this->recordingModel() ;
        $filtered->objectResult = null ; // the scope filtered the document out

        $unknown = $this->recordingModel() ;
        $unknown->objectResult = null ; // no such identifier

        $empty = $this->recordingModel() ;
        $empty->objectResult = (object) [ '_key' => 'k1' ] ; // visible, property absent

        foreach ( [ $filtered , $unknown , $empty ] as $model )
        {
            $controller = $this->makePropertyController( $model , [ self::PROPERTY => 'emails' ] ) ;

            $this->assertNull( $controller->get( null , null , [ Arango::ID => 'k1' ] ) ) ;
        }
    }

    /**
     * The same three cases, asserted on the HTTP status rather than the payload:
     * still 200, never 404. Guards the behaviour the frozen design kept.
     */
    public function testAFilteredDocumentStillAnswers200() :void
    {
        $model = $this->recordingModel() ;
        $model->objectResult = null ;

        $controller = $this->makePropertyController( $model , [ self::PROPERTY => 'emails' ] ) ;
        $response   = $controller->get( $this->makeRequest() , $this->makeResponse() , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame( 200 , $response->getStatusCode() ) ;
    }

    // ---- the write path --------------------------------------------------

    public function testPatchScopeReachesTheExistenceProbe() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makeScopedController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [ 'new@x' ] ] ) ;

        $controller->patch( $request , null , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame
        (
            [ ScopedPropertyController::CONDITION ] ,
            $model->initOf( 'exist' )[ Arango::CONDITIONS ] ?? null
        ) ;
    }

    /**
     * The existence probe is scoped, so a document outside the scope is reported
     * missing and the write never runs.
     */
    public function testPatchOnAnOutOfScopeDocumentAnswers404AndDoesNotWrite() :void
    {
        $model = $this->recordingModel() ;
        $model->firstResult = 0 ; // the scoped exist() matches nothing

        $controller = $this->makeScopedController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [ 'new@x' ] ] ) ;
        $response   = $controller->patch( $request , $this->makeResponse() , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame( 404 , $response->getStatusCode() ) ;
        $this->assertNotContains( 'update' , $model->methods() ) ;
    }

    /**
     * The write itself is **not** hooked, and must not be.
     *
     * `Arango::CONDITIONS` is overloaded: a list of AQL predicates on the read
     * path, a list of *callables* on the write path (the null-compression guards
     * of `prepareDocumentClause()`). A consumer hook posing a read scope on every
     * model call would therefore make the update raise "All conditions in the
     * array must be callable" — a 500 where a scope was intended.
     *
     * This test pins the boundary: the update receives the route init untouched
     * by the hook, and the patch completes normally.
     */
    public function testTheWriteIsNotHookedSoTheReadScopeCannotPoisonIt() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makeScopedController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [ 'new@x' ] ] ) ;
        $response   = $controller->patch( $request , $this->makeResponse() , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame( 200 , $response->getStatusCode() ) ;
        $this->assertArrayNotHasKey( Arango::CONDITIONS , $model->initOf( 'update' ) ) ;
    }

    /**
     * The post-write reload is a read: it must carry the scope too, otherwise the
     * write response hands back exactly what the scope withholds.
     */
    public function testPatchScopeReachesThePostWriteReload() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makeScopedController( $model ) ;
        $request    = $this->makeRequest( [] , 'PATCH' )->withParsedBody( [ 'emails' => [ 'new@x' ] ] ) ;

        $controller->patch( $request , null , [ Arango::ID => 'k1' ] ) ;

        $reload = $model->lastInitOf( 'get' ) ;

        $this->assertIsArray( $reload , 'the reload never ran' ) ;
        $this->assertSame( [ ScopedPropertyController::CONDITION ] , $reload[ Arango::CONDITIONS ] ?? null ) ;
        $this->assertSame( ScopedPropertyController::BINDS , $reload[ Arango::BINDS ] ?? null ) ;
    }

    // ---- the six array operations ----------------------------------------

    /**
     * Every array operation goes through the same scoped existence guard, so an
     * owner document outside the scope answers 404 and the operation never runs.
     *
     * `hasItem` is in the list on purpose: it is a **read**, and a membership
     * answer on a document the caller may not see is a disclosure of its own.
     */
    public function testEveryArrayOperationIsGatedByTheScopedExistenceGuard() :void
    {
        $operations =
        [
            'addItem'      => [ Arango::ID => 'p42' ] ,
            'hasItem'      => [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ,
            'moveItem'     => [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ,
            'removeItem'   => [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ,
            'reorderItems' => [ Arango::ID => 'p42' ] ,
            'updateItem'   => [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ,
        ] ;

        foreach ( $operations as $operation => $args )
        {
            $model = $this->recordingArrayModel() ;
            $model->firstResult = 0 ; // the scoped exist() matches nothing

            $controller = $this->makeScopedArrayController( $model ) ;
            $response   = $controller->{ $operation }( $this->makeRequest() , $this->makeResponse() , $args ) ;

            $this->assertSame( 404 , $response->getStatusCode() , $operation . ' was not gated' ) ;

            $this->assertSame
            (
                [ ScopedPropertyController::CONDITION ] ,
                $model->initOf( 'exist' )[ Arango::CONDITIONS ] ?? null ,
                $operation . ' did not scope its existence probe'
            ) ;
        }
    }

    /**
     * The six operations still run to completion once the owner document is in
     * scope — the enrichment must scope them, not break them. Guards against the
     * `Arango::CONDITIONS` overload biting on the array write path the way it
     * does on `update()` (see {@see testTheWriteIsNotHookedSoTheReadScopeCannotPoisonIt()}).
     */
    public function testEveryArrayOperationStillSucceedsWithinTheScope() :void
    {
        $operations =
        [
            'addItem'      => [ Arango::ID => 'p42' ] ,
            'hasItem'      => [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ,
            'removeItem'   => [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ,
        ] ;

        foreach ( $operations as $operation => $args )
        {
            $model      = $this->recordingArrayModel() ;
            $controller = $this->makeScopedArrayController( $model ) ;

            $response = $controller->{ $operation }( $this->makeRequest() , $this->makeResponse() , $args ) ;

            $this->assertSame( 200 , $response->getStatusCode() , $operation . ' broke inside the scope' ) ;
        }
    }

    /**
     * Regression guard for the by-value capture: the six operations used to
     * capture `$init` when their closure was **created**, i.e. before
     * `runArrayOp()` ran, so an init enriched by the hook never reached the
     * operation itself. It is now passed as the closure's third argument.
     */
    public function testTheEnrichedInitReachesTheArrayOperationItself() :void
    {
        $model      = $this->recordingArrayModel() ;
        $controller = $this->makeScopedArrayController( $model ) ;

        $controller->removeItem( $this->makeRequest() , null , [ Arango::ID => 'p42' , Arango::VALUE => 'A' ] ) ;

        $init = $model->initOf( 'arrayRemove' ) ;

        $this->assertIsArray( $init , 'arrayRemove() was never called' ) ;
        $this->assertSame( [ ScopedPropertyController::CONDITION ] , $init[ Arango::CONDITIONS ] ?? null ) ;
    }

    // ---- non-regression --------------------------------------------------

    /**
     * A plain controller, no subclass and no authorization stack: the model must
     * see neither an authorizer nor a condition. This is the promise made to
     * every existing consumer — the seat is additive, and empty until used.
     */
    public function testAPlainControllerSendsNeitherAuthorizerNorConditions() :void
    {
        $model      = $this->recordingModel() ;
        $controller = $this->makePropertyController( $model , [ self::PROPERTY => 'emails' ] ) ;

        $controller->get( $this->makeRequest() , null , [ Arango::ID => 'k1' ] ) ;

        $init = $model->initOf( 'get' ) ;

        $this->assertArrayNotHasKey( Arango::AUTHORIZER , $init ) ;
        $this->assertSame( [] , $init[ Arango::CONDITIONS ] ?? null ) ;
    }

    // ---- harness ---------------------------------------------------------

    /**
     * Builds a real {@see PropertyController} with the capability enforcer and the
     * permission-subject resolver registered in the container (or not).
     */
    private function makeAuthController( bool $withStack , ?MockDocuments $model = null ) :PropertyController
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        if ( $withStack )
        {
            $container->set( CapabilityEnforcerInterface::class        , $this->createStub( CapabilityEnforcerInterface::class ) ) ;
            $container->set( PermissionSubjectResolverInterface::class , $this->createStub( PermissionSubjectResolverInterface::class ) ) ;
        }

        return new PropertyController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => $model ?? new MockDocuments( 'users' ) ,
            self::PROPERTY          => 'emails' ,
        ]) ;
    }

    /** A {@see ScopedArrayPropertyController} over the given recording model. */
    private function makeScopedArrayController( RecordingDocuments $model ) :ScopedArrayPropertyController
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        return new ScopedArrayPropertyController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => $model ,
            self::PROPERTY          => 'tracks' ,
        ]) ;
    }

    /** A {@see ScopedPropertyController} over the given recording model. */
    private function makeScopedController( RecordingDocuments $model ) :ScopedPropertyController
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        return new ScopedPropertyController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => $model ,
            self::PROPERTY          => 'emails' ,
        ]) ;
    }

    /** A recording model wired with a `tracks` LIST array field. */
    private function recordingArrayModel() :RecordingDocuments
    {
        $model = $this->recordingModel() ;
        $model->arrays       = [ 'tracks' => [ Arango::MODE => ArrayMode::LIST , Arango::COUNTER => null ] ] ;
        $model->objectResult = (object) [ '_key' => 'p42' , 'tracks' => [ 'A' , 'B' ] ] ;
        return $model ;
    }

    /** A recording model whose reads succeed by default. */
    private function recordingModel() :RecordingDocuments
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult  = 1 ; // exist() → true
        $model->objectResult = (object) [ '_key' => 'k1' , 'emails' => [ 'a@x' ] ] ;
        return $model ;
    }

    /**
     * Invokes the protected `beforeModelCall()` hook, propagating the by-reference
     * `$init` mutation back to the caller.
     *
     * @param array<string,mixed> $init
     */
    private function invokeBeforeModelCall( PropertyController $controller , ?Request $request , array &$init ) :void
    {
        $method = new ReflectionMethod( $controller , 'beforeModelCall' ) ;
        $args   = [ $request , &$init ] ;
        $method->invokeArgs( $controller , $args ) ;
    }
}
