<?php

namespace tests\oihana\arango\controllers;

use Closure;
use ReflectionMethod;

use DI\Container;

use PHPUnit\Framework\Attributes\CoversClass;

use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Factory\AppFactory;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;
use oihana\auth\CapabilityEnforcerInterface;
use oihana\auth\PermissionSubjectResolverInterface;
use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\RequestAttribute;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for the capability/authorization wiring of {@see DocumentsController} :
 * the constructor resolving a {@see CapabilityEnforcerInterface} and a
 * {@see PermissionSubjectResolverInterface} from the container, and the
 * `beforeModelCall()` hook posing the request-scoped authorizer under
 * `Arango::AUTHORIZER` (or leaving it untouched when the stack, the user or a
 * pre-set authorizer make it a no-op).
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( DocumentsController::class )]
final class DocumentsControllerGatingTest extends ControllerTestCase
{
    public function testConstructorResolvesEnforcerAndSubjectResolverFromContainer() :void
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $container->set( CapabilityEnforcerInterface::class       , $this->createStub( CapabilityEnforcerInterface::class ) ) ;
        $container->set( PermissionSubjectResolverInterface::class , $this->createStub( PermissionSubjectResolverInterface::class ) ) ;

        $controller = new DocumentsController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => new MockDocuments( 'users' ) ,
        ]);

        $this->assertInstanceOf( DocumentsController::class , $controller ) ;
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

    public function testBeforeModelCallSkipsWithoutAuthenticatedUser() :void
    {
        $controller = $this->makeAuthController( withStack: true ) ;
        $request    = $this->makeRequest() ; // no USER_ID attribute → buildPermissionAuthorizer() returns null

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

    public function testBeforeModelCallSkipsWithoutAuthorizationStack() :void
    {
        $controller = $this->makeAuthController( withStack: false ) ;
        $request    = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $init = [] ;
        $this->invokeBeforeModelCall( $controller , $request , $init ) ;

        $this->assertArrayNotHasKey( Arango::AUTHORIZER , $init ) ;
    }

    public function testBeforeModelCallLeavesAnExistingAuthorizerUntouched() :void
    {
        $controller = $this->makeAuthController( withStack: true ) ;
        $request    = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $sentinel = static fn( string $subject ) : bool => true ;
        $init     = [ Arango::AUTHORIZER => $sentinel ] ;

        $this->invokeBeforeModelCall( $controller , $request , $init ) ;

        $this->assertSame( $sentinel , $init[ Arango::AUTHORIZER ] ) ;
    }

    /**
     * Regression guard for the meta-only authorizer leak (audit T1): in
     * `?metaOnly=true`, the document-fetch is skipped but count(), facetCounts()
     * and bounds() still run — they MUST receive the request-scoped authorizer,
     * otherwise every Field::REQUIRES / Facet::REQUIRES gate falls open and the
     * sidebar becomes an inference oracle over hidden dimensions.
     */
    public function testMetaOnlyPropagatesAuthorizerToCountFacetCountsAndBounds() :void
    {
        $model      = $this->makeCapturingModel() ;
        $controller = $this->makeAuthController( withStack: true , model: $model ) ;

        $request = $this->makeRequest([ Arango::META_ONLY => 'true' , Arango::FACET_COUNTS => 'type' , Arango::BOUNDS => 'price' ])
                        ->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $controller->list( $request , $this->makeResponse() ) ;

        $this->assertAuthorizerPresent( $model->countInit       , 'count()'       ) ;
        $this->assertAuthorizerPresent( $model->facetCountsInit , 'facetCounts()' ) ;
        $this->assertAuthorizerPresent( $model->boundsInit      , 'bounds()'      ) ;
    }

    /**
     * The deprecated `?facetsOnly=true` alias goes through the same meta-only
     * branch and must propagate the authorizer identically.
     */
    public function testFacetsOnlyAliasPropagatesAuthorizerToMetaQueries() :void
    {
        $model      = $this->makeCapturingModel() ;
        $controller = $this->makeAuthController( withStack: true , model: $model ) ;

        $request = $this->makeRequest([ Arango::FACETS_ONLY => 'true' , Arango::FACET_COUNTS => 'type' , Arango::BOUNDS => 'price' ])
                        ->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $controller->list( $request , $this->makeResponse() ) ;

        $this->assertAuthorizerPresent( $model->countInit       , 'count()'       ) ;
        $this->assertAuthorizerPresent( $model->facetCountsInit , 'facetCounts()' ) ;
        $this->assertAuthorizerPresent( $model->boundsInit      , 'bounds()'      ) ;
    }

    /**
     * The normal (non meta-only) path keeps propagating the authorizer to the
     * document-fetch query — the fix moved beforeModelCall() before the branch,
     * it must not have regressed the standard list().
     */
    public function testNormalListStillPropagatesAuthorizerToListQuery() :void
    {
        $model      = $this->makeCapturingModel() ;
        $controller = $this->makeAuthController( withStack: true , model: $model ) ;

        $request = $this->makeRequest()->withAttribute( RequestAttribute::USER_ID , 'user-1' ) ;

        $controller->list( $request , $this->makeResponse() ) ;

        $this->assertAuthorizerPresent( $model->listInit , 'list()' ) ;
        $this->assertNull( $model->countInit , 'count() must not run on the normal list path' ) ;
    }

    /**
     * Asserts the captured `$init` carries a callable authorizer under
     * {@see Arango::AUTHORIZER}.
     *
     * @param array<string,mixed>|null $init  The `$init` captured by a model seam.
     * @param string                   $label The seam name, for the failure message.
     */
    private function assertAuthorizerPresent( ?array $init , string $label ) :void
    {
        $this->assertIsArray( $init , $label . ' was never called' ) ;
        $this->assertArrayHasKey( Arango::AUTHORIZER , $init , $label . ' did not receive the authorizer' ) ;
        $this->assertInstanceOf( Closure::class , $init[ Arango::AUTHORIZER ] , $label . ' authorizer is not a Closure' ) ;
    }

    /**
     * A MockDocuments double that captures the `$init` each meta query receives,
     * so the test can assert the authorizer reached them.
     */
    private function makeCapturingModel() :MockDocuments
    {
        return new class( 'users' ) extends MockDocuments
        {
            public ?array $countInit       = null ;
            public ?array $facetCountsInit  = null ;
            public ?array $boundsInit       = null ;
            public ?array $listInit         = null ;

            public function count( array $init = [] ) :int
            {
                $this->countInit = $init ;
                return 0 ;
            }

            public function facetCounts( array $init = [] ) :array
            {
                $this->facetCountsInit = $init ;
                return [] ;
            }

            public function bounds( array $init = [] ) :array
            {
                $this->boundsInit = $init ;
                return [] ;
            }

            public function list( array $init = [] ) :array
            {
                $this->listInit = $init ;
                return [] ;
            }
        } ;
    }

    /**
     * Invokes the protected `beforeModelCall()` hook, propagating the by-reference
     * `$init` mutation back to the caller.
     *
     * @param array<string,mixed> $init
     */
    private function invokeBeforeModelCall( DocumentsController $controller , ?Request $request , array &$init ) :void
    {
        $method = new ReflectionMethod( $controller , 'beforeModelCall' ) ;
        $args   = [ $request , &$init ] ;
        $method->invokeArgs( $controller , $args ) ;
    }

    /**
     * Builds a real {@see DocumentsController} over a MockDocuments model, with
     * the capability enforcer and the permission-subject resolver registered in
     * the container (or not, when `$withStack` is false).
     */
    private function makeAuthController( bool $withStack , ?MockDocuments $model = null ) :DocumentsController
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        if ( $withStack )
        {
            $container->set( CapabilityEnforcerInterface::class       , $this->createStub( CapabilityEnforcerInterface::class ) ) ;
            $container->set( PermissionSubjectResolverInterface::class , $this->createStub( PermissionSubjectResolverInterface::class ) ) ;
        }

        return new DocumentsController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => $model ?? new MockDocuments( 'users' ) ,
        ]);
    }
}
