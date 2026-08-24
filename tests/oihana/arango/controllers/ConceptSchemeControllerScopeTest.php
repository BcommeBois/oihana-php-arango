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

use oihana\arango\controllers\ConceptSchemeController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\filters\FilterQuantifier;
use oihana\arango\controllers\enums\ModelOperation;

use oihana\auth\CapabilityEnforcerInterface;
use oihana\auth\PermissionSubjectResolverInterface;

use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\RequestAttribute;

use xyz\oihana\schema\constants\Oihana;
use xyz\oihana\schema\thesaurus\ConceptScheme;

use tests\oihana\arango\controllers\mocks\ScopedConceptSchemeController;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for the **authorization seat** of {@see ConceptSchemeController} : the
 * `list()` call wrapped by the {@see \oihana\controllers\traits\ModelCallTrait}
 * hooks, and the fact that a consumer subclass can impose a scope the caller
 * cannot widen through `?filter=`.
 *
 * The lib provides the seat, never the rule: nothing here names a business
 * concept. A consumer supplies the predicate, which is what
 * {@see ScopedConceptSchemeController} stands in for.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( ConceptSchemeController::class )]
final class ConceptSchemeControllerScopeTest extends ControllerTestCase
{
    /** The root constraint the controller always poses first. */
    private const array ROOT = [ FilterParam::KEY => Oihana::BROADER , FilterParam::QUANT => FilterQuantifier::NONE ] ;

    // ---- the scope alone -------------------------------------------------

    public function testTheHookPredicateReachesTheModelFilter() :void
    {
        $model = $this->model() ;

        $this->makeScopedController( $model )->get( $this->makeRequest() ) ;

        // No ?filter= : the hook ANDs its predicate with the root constraint.
        $this->assertSame
        (
            [ FilterLogic::AND , ScopedConceptSchemeController::PREDICATE , self::ROOT ] ,
            $model->listInit[ Arango::FILTER ] ?? null
        ) ;
    }

    public function testTheHookConditionsReachTheModel() :void
    {
        $model = $this->model() ;

        $this->makeScopedController( $model )->get( $this->makeRequest() ) ;

        $this->assertSame
        (
            [ ScopedConceptSchemeController::CONDITION ] ,
            $model->listInit[ Arango::CONDITIONS ] ?? null
        ) ;
    }

    // ---- the scope vs the client filter ----------------------------------

    /**
     * The hook runs **after** `?filter=` is folded in, so the client predicate is
     * already one operand of the root `and` when the scope wraps the whole thing.
     */
    public function testTheClientFilterEntersUnderTheScopeNotBesideIt() :void
    {
        $model = $this->model() ;

        $urlFilter = [ FilterParam::KEY => 'inScheme' , FilterParam::VAL => 'animals' ] ;

        $this->makeScopedController( $model )->get( $this->makeRequest( [ ControllerParam::FILTER => json_encode( $urlFilter ) ] ) ) ;

        $this->assertSame
        (
            [
                FilterLogic::AND ,
                ScopedConceptSchemeController::PREDICATE ,
                [ FilterLogic::AND , self::ROOT , $urlFilter ] ,
            ] ,
            $model->listInit[ Arango::FILTER ] ?? null
        ) ;
    }

    /**
     * The shape that would break a spliced merge : a disjunctive client filter.
     * It must stay ONE intact operand under the scope — `scope && ( root && ( a || b ) )`
     * — never `scope || a || b`, which would hand back every concept.
     */
    public function testAClientOrGroupCannotDegradeTheScope() :void
    {
        $model = $this->model() ;

        $orGroup =
        [
            FilterLogic::OR ,
            [ FilterParam::KEY => 'a' , FilterParam::VAL => 1 ] ,
            [ FilterParam::KEY => 'b' , FilterParam::VAL => 2 ] ,
        ] ;

        $this->makeScopedController( $model )->get( $this->makeRequest( [ ControllerParam::FILTER => json_encode( $orGroup ) ] ) ) ;

        $got = $model->listInit[ Arango::FILTER ] ?? null ;

        $this->assertSame( FilterLogic::AND                              , $got[ 0 ] ?? null ) ;
        $this->assertSame( ScopedConceptSchemeController::PREDICATE      , $got[ 1 ] ?? null ) ;
        $this->assertSame( [ FilterLogic::AND , self::ROOT , $orGroup ]  , $got[ 2 ] ?? null ) ;
        $this->assertCount( 3 , $got ) ;
    }

    // ---- the result ------------------------------------------------------

    public function testAfterModelCallReceivesAndCanReplaceTheRoots() :void
    {
        $model = $this->model( [ [ '_key' => 'stored' ] ] ) ;

        $scheme = $this->makeScopedController( $model )->get( $this->makeRequest() ) ;

        $this->assertSame
        (
            ScopedConceptSchemeController::REPLACEMENT ,
            $scheme->jsonSerialize()[ ConceptScheme::HAS_TOP_CONCEPT ]
        ) ;
    }

    /**
     * `afterModelCall()` may replace the result outright — including with a
     * non-list — so the envelope re-establishes the shape it counts rather than
     * assuming it.
     */
    public function testANonListResultDegradesToAnEmptyScheme() :void
    {
        $model      = $this->model( [ [ '_key' => 'stored' ] ] ) ;
        $controller = new class( ...$this->controllerArgsFor( $model ) ) extends ConceptSchemeController
        {
            protected function afterModelCall( ?Request $request , array &$init , mixed &$result ) :void
            {
                $result = 'not a list' ;
            }
        } ;

        $scheme = $controller->get( $this->makeRequest() ) ;

        $this->assertSame( [] , $scheme->jsonSerialize()[ ConceptScheme::HAS_TOP_CONCEPT ] ) ;
    }

    // ---- the authorizer --------------------------------------------------

    /**
     * The request-scoped authorizer is posed **before** the hook, so an override
     * calling `parent::beforeModelCall()` finds it already there — and the trait's
     * no-op leaves it untouched.
     */
    public function testTheAuthorizerIsPosedBeforeTheHookRuns() :void
    {
        $model      = $this->model() ;
        $controller = $this->makeScopedController( $model , withStack: true ) ;
        $request    = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $controller->get( $request ) ;

        $this->assertInstanceOf( Closure::class , $model->listInit[ Arango::AUTHORIZER ] ?? null ) ;
    }

    public function testACallerAuthorizerStillWins() :void
    {
        $model      = $this->model() ;
        $sentinel   = static fn( string $subject ) :bool => true ;

        $this->makeScopedController( $model , withStack: true )
             ->get( $this->makeRequest() , null , [] , [ Arango::AUTHORIZER => $sentinel ] ) ;

        $this->assertSame( $sentinel , $model->listInit[ Arango::AUTHORIZER ] ?? null ) ;
    }

    // ---- non-regression --------------------------------------------------

    /**
     * A plain controller, no subclass and no authorization stack: the model must
     * see exactly what it saw before the seat existed — the bare root constraint,
     * no conditions, and a null authorizer.
     */
    public function testAPlainControllerSendsNeitherScopeNorConditions() :void
    {
        $model = $this->model() ;

        $this->makeController( $model )->get( $this->makeRequest() ) ;

        $this->assertSame( self::ROOT , $model->listInit[ Arango::FILTER     ] ?? null ) ;
        $this->assertNull ( $model->listInit[ Arango::AUTHORIZER ] ?? null ) ;
        $this->assertArrayNotHasKey( Arango::CONDITIONS , $model->listInit ) ;
    }

    /**
     * The default hooks are no-ops: invoking them leaves the init untouched.
     */
    public function testTheDefaultHooksAreNoOps() :void
    {
        $controller = $this->makeController( $this->model() ) ;

        $init   = [ Arango::FILTER => self::ROOT ] ;
        $result = [ 'untouched' ] ;

        $before = new ReflectionMethod( $controller , 'beforeModelCall' ) ;
        $after  = new ReflectionMethod( $controller , 'afterModelCall'  ) ;

        $before->invokeArgs( $controller , [ $this->makeRequest() , &$init ] ) ;
        $after ->invokeArgs( $controller , [ $this->makeRequest() , &$init , &$result ] ) ;

        $this->assertSame( [ Arango::FILTER => self::ROOT ] , $init ) ;
        $this->assertSame( [ 'untouched' ]                  , $result ) ;
    }

    // ---- harness ---------------------------------------------------------

    /** The constructor arguments of a controller over the given model. */
    private function controllerArgsFor( MockDocuments $model , bool $withStack = false ) :array
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $container->set( 'thesaurus.model'      , $model ) ;
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
                ControllerParam::APP           => $app ,
                ControllerParam::ROUTER        => $app->getRouteCollector()->getRouteParser() ,
                ConceptSchemeController::MODEL => 'thesaurus.model' ,
                ConceptSchemeController::TITLE => 'Product categories' ,
            ]
        ] ;
    }

    /** A plain controller over the given model. */
    private function makeController( MockDocuments $model ) :ConceptSchemeController
    {
        return new ConceptSchemeController( ...$this->controllerArgsFor( $model ) ) ;
    }

    /** A consumer subclass over the given model. */
    private function makeScopedController( MockDocuments $model , bool $withStack = false ) :ScopedConceptSchemeController
    {
        return new ScopedConceptSchemeController( ...$this->controllerArgsFor( $model , $withStack ) ) ;
    }

    /** A MockDocuments whose list() captures its init and returns canned roots. */
    private function model( array $roots = [] ) :MockDocuments
    {
        return new class( $roots ) extends MockDocuments
        {
            public array $listInit = [] ;

            public function __construct( private array $roots ) { parent::__construct( 'categories' ) ; }

            public function list( array $init = [] ) :array
            {
                $this->listInit = $init ;
                return $this->roots ;
            }
        } ;
    }

    /**
     * The scheme roots are a listing like any other, and say so.
     */
    public function testTheRootListingIsAnnouncedAsAList() :void
    {
        $model = $this->model() ;

        $this->makeController( $model )->get( $this->makeRequest() ) ;

        $this->assertSame( ModelOperation::LIST , $model->listInit[ Arango::OPERATION ] ?? null ) ;
    }

}
