<?php

namespace tests\oihana\arango\controllers;

use DI\Container;

use PHPUnit\Framework\Attributes\CoversClass;

use Slim\Factory\AppFactory;

use oihana\arango\controllers\DocumentsController;
use oihana\auth\CapabilityEnforcerInterface;
use oihana\auth\PermissionSubjectResolverInterface;
use oihana\controllers\enums\ControllerParam;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for the capability-gating wiring of {@see DocumentsController}'s
 * constructor: when the container exposes a {@see CapabilityEnforcerInterface}
 * and a {@see PermissionSubjectResolverInterface}, both are resolved and kept
 * (the `$container->get(...)` arms the base ControllerTestCase deliberately
 * leaves uncovered by registering neither).
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
}
