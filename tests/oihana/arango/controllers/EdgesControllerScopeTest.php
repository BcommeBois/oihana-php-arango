<?php

namespace tests\oihana\arango\controllers;

use Closure;
use ReflectionMethod;

use DI\Container;

use PHPUnit\Framework\Attributes\CoversClass;

use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Factory\AppFactory;

use oihana\arango\controllers\EdgesController;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use oihana\auth\CapabilityEnforcerInterface;
use oihana\auth\PermissionSubjectResolverInterface;

use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\RequestAttribute;

use org\schema\constants\Schema;

use tests\oihana\arango\controllers\mocks\RecordingDocuments;
use tests\oihana\arango\controllers\mocks\RecordingEdges;
use tests\oihana\arango\controllers\mocks\ScopedEdgesController;

/**
 * Coverage for the **authorization seat** of {@see EdgesController} : the vertex
 * probes and the edge calls wrapped by the
 * {@see \oihana\controllers\traits\ModelCallTrait} hooks, and the
 * {@see EdgesController::CALL} discriminator that lets one hook serve three
 * collections.
 *
 * Without the seat this surface both **wrote** outside any scope — a `POST` links
 * two documents a scoped `GET` refuses to show — and answered as an existence
 * oracle through its three refusals (404 source, 404 target, 409 edge exists).
 *
 * The lib provides the seat, never the rule: nothing here names a business
 * concept. {@see ScopedEdgesController} stands in for the consumer.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( EdgesController::class )]
final class EdgesControllerScopeTest extends ControllerTestCase
{
    // ---- one hook, three collections -------------------------------------

    /**
     * The source probe gets the source predicate — and **only** it. A single
     * undiscriminated predicate applied to all three collections is exactly what
     * `CALL` exists to prevent.
     */
    public function testTheSourceProbeCarriesItsOwnPredicate() :void
    {
        [ $controller , $edges , $from , $to ] = $this->scoped() ;

        $controller->post( $this->makeRequest() , null , $this->args() ) ;

        $init = $from->initOf( 'exist' ) ;

        $this->assertSame( EdgesController::FROM , $init[ EdgesController::CALL ] ?? null ) ;
        $this->assertSame( [ ScopedEdgesController::FROM_CONDITION ] , $init[ Arango::CONDITIONS ] ?? null ) ;
        $this->assertSame( ScopedEdgesController::BINDS , $init[ Arango::BINDS ] ?? null ) ;
    }

    public function testTheTargetProbeCarriesItsOwnPredicate() :void
    {
        [ $controller , $edges , $from , $to ] = $this->scoped() ;

        $controller->post( $this->makeRequest() , null , $this->args() ) ;

        $init = $to->initOf( 'exist' ) ;

        $this->assertSame( EdgesController::TO , $init[ EdgesController::CALL ] ?? null ) ;
        $this->assertSame( [ ScopedEdgesController::TO_CONDITION ] , $init[ Arango::CONDITIONS ] ?? null ) ;
    }

    /**
     * The edge collection is reached through the filter slot its model reads,
     * and the probe and the deletion share **one** init — so they cannot disagree.
     */
    public function testTheEdgeProbeAndTheDeletionShareTheSameScopedInit() :void
    {
        [ $controller , $edges ] = $this->scoped() ;

        $controller->delete( $this->makeRequest() , null , $this->args() ) ;

        $probe   = $edges->initOf( 'existEdge'  ) ;
        $removal = $edges->initOf( 'deleteEdge' ) ;

        $this->assertSame( [ ScopedEdgesController::EDGE_FILTER ] , $probe[ AQL::FILTER ] ?? null ) ;
        $this->assertSame( $probe , $removal , 'the probe and the deletion must not disagree about the scope' ) ;
    }

    /**
     * An edge the scope hides is reported missing, and **nothing is deleted** —
     * the 200-with-an-empty-result gap that `DocumentsController::delete()` still
     * has cannot appear here.
     */
    public function testAHiddenEdgeIsReportedMissingAndNeverDeleted() :void
    {
        [ $controller , $edges ] = $this->scoped() ;

        $edges->edgeExists = false ; // the scoped probe finds nothing

        $controller->delete( $this->makeRequest() , $this->makeResponse() , $this->args() ) ;

        $this->assertNull( $edges->initOf( 'deleteEdge' ) , 'a hidden edge was deleted' ) ;
    }

    // ---- the creation is deliberately left unhooked ----------------------

    /**
     * `insertEdge()` forwards its init to the `existEdge()` uniqueness check, so a
     * scope posed there would blind the 409 and let a duplicate through. The
     * creation therefore carries no consumer predicate — its gate is the two
     * vertex probes above.
     */
    public function testTheCreationCarriesNoConsumerPredicate() :void
    {
        [ $controller , $edges ] = $this->scoped() ;

        $controller->post( $this->makeRequest() , null , $this->args() ) ;

        $init = $edges->initOf( 'insertEdge' ) ;

        $this->assertIsArray( $init , 'insertEdge() was never called' ) ;
        $this->assertArrayNotHasKey( AQL::FILTER          , $init ) ;
        $this->assertArrayNotHasKey( Arango::CONDITIONS   , $init ) ;
        $this->assertArrayNotHasKey( EdgesController::CALL , $init ) ;
    }

    /**
     * The authorizer, however, IS posed on the creation: the edge it returns must
     * be projected under the same `Field::REQUIRES` gates as a read.
     */
    public function testTheCreationStillCarriesTheAuthorizer() :void
    {
        [ $controller , $edges ] = $this->scoped( withStack: true ) ;

        $controller->post( $this->authenticated() , null , $this->args() ) ;

        $this->assertInstanceOf( Closure::class , $edges->initOf( 'insertEdge' )[ Arango::AUTHORIZER ] ?? null ) ;
    }

    // ---- the authorizer --------------------------------------------------

    public function testTheProbesCarryTheRequestAuthorizer() :void
    {
        [ $controller , $edges , $from ] = $this->scoped( withStack: true ) ;

        $controller->post( $this->authenticated() , null , $this->args() ) ;

        $this->assertInstanceOf( Closure::class , $from->initOf( 'exist' )[ Arango::AUTHORIZER ] ?? null ) ;
    }

    public function testACallerAuthorizerStillWins() :void
    {
        [ $controller , $edges , $from ] = $this->scoped( withStack: true ) ;

        $sentinel = static fn( string $subject ) :bool => true ;

        $controller->post( $this->authenticated() , null , $this->args() , [ Arango::AUTHORIZER => $sentinel ] ) ;

        $this->assertSame( $sentinel , $from->initOf( 'exist' )[ Arango::AUTHORIZER ] ?? null ) ;
    }

    public function testBeforeModelCallPosesNothingWithoutAnAuthorizationStack() :void
    {
        [ $controller ] = $this->scoped() ;

        $init = [] ;
        $method = new ReflectionMethod( $controller , 'beforeModelCall' ) ;
        $args   = [ $this->makeRequest() , &$init ] ;
        $method->invokeArgs( $controller , $args ) ;

        $this->assertArrayNotHasKey( Arango::AUTHORIZER , $init ) ;
    }

    // ---- the result ------------------------------------------------------

    public function testAfterModelCallReceivesTheDeletionResult() :void
    {
        [ $controller , $edges ] = $this->scoped() ;

        $edges->deleted = (object) [ '_key' => 'e1' ] ;

        $controller->delete( $this->makeRequest() , null , $this->args() ) ;

        $this->assertSame( $edges->deleted , $controller->seen ) ;
    }

    // ---- the static half of the seat -------------------------------------

    /**
     * Conditions declared once in the route `$init` reach the probes without any
     * subclass — `post()` and `delete()` used to drop that init entirely.
     */
    public function testDefinitionLevelConditionsReachTheProbes() :void
    {
        $edges = new RecordingEdges( 'user_has_roles' ) ;
        $from  = $this->vertexModel() ;

        $controller = $this->makeEdgesController( $edges , $from , null ) ;

        $controller->post( $this->makeRequest() , null , $this->args() , [ Arango::CONDITIONS => [ 'doc.active == true' ] ] ) ;

        $this->assertSame( [ 'doc.active == true' ] , $from->initOf( 'exist' )[ Arango::CONDITIONS ] ?? null ) ;
    }

    // ---- non-regression --------------------------------------------------

    /**
     * A plain controller, no subclass and no authorization stack: the models must
     * see neither an authorizer nor a predicate — only the probed value and the
     * discriminator.
     */
    public function testAPlainControllerSendsNeitherAuthorizerNorPredicate() :void
    {
        $edges = new RecordingEdges( 'user_has_roles' ) ;
        $from  = $this->vertexModel() ;

        $controller = $this->makeEdgesController( $edges , $from , null ) ;

        $controller->post( $this->makeRequest() , null , $this->args() ) ;

        $init = $from->initOf( 'exist' ) ;

        $this->assertSame( [ Arango::VALUE => 'users/u1' , EdgesController::CALL => EdgesController::FROM ] , $init ) ;
        $this->assertSame( [] , $edges->initOf( 'insertEdge' ) ) ;
    }

    // ---- harness ---------------------------------------------------------

    /** The route placeholders of both verbs. */
    private function args() :array
    {
        return [ Schema::ID => 'users/u1' , EdgesController::TARGET_ID => 'roles/r1' ] ;
    }

    /** A request carrying an authenticated user. */
    private function authenticated() :Request
    {
        return $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;
    }

    /**
     * A {@see ScopedEdgesController} over recording doubles.
     *
     * @return array{0:ScopedEdgesController,1:RecordingEdges,2:RecordingDocuments,3:RecordingDocuments}
     */
    private function scoped( bool $withStack = false ) :array
    {
        $edges = new RecordingEdges( 'user_has_roles' ) ;
        $from  = $this->vertexModel() ;
        $to    = $this->vertexModel() ;

        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $container->set( 'edges.service' , $edges ) ;
        $container->set( 'from.service'  , $from  ) ;
        $container->set( 'to.service'    , $to    ) ;

        if ( $withStack )
        {
            $container->set( CapabilityEnforcerInterface::class        , $this->createStub( CapabilityEnforcerInterface::class ) ) ;
            $container->set( PermissionSubjectResolverInterface::class , $this->createStub( PermissionSubjectResolverInterface::class ) ) ;
        }

        $controller = new ScopedEdgesController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            EdgesController::EDGES  => 'edges.service' ,
            EdgesController::FROM   => 'from.service' ,
            EdgesController::TO     => 'to.service' ,
        ]) ;

        return [ $controller , $edges , $from , $to ] ;
    }

    /** A vertex model whose probe answers "it exists". */
    private function vertexModel() :RecordingDocuments
    {
        $model = new RecordingDocuments( 'users' ) ;
        $model->firstResult = 1 ; // exist() → true
        return $model ;
    }
}
