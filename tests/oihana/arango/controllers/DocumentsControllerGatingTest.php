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
    private function makeAuthController( bool $withStack ) :DocumentsController
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
            ControllerParam::MODEL  => new MockDocuments( 'users' ) ,
        ]);
    }
}
