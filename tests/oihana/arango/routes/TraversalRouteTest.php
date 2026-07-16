<?php

namespace tests\oihana\arango\routes;

use DI\Container;

use oihana\arango\controllers\TraversalController;
use oihana\arango\routes\TraversalRoute;

use oihana\routes\Route;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Coverage for {@see TraversalRoute} — registers, from a single definition, the
 * four navigation sub-routes of a {@see TraversalController}.
 *
 * @package tests\oihana\arango\routes
 * @author  Marc Alcaraz
 */
#[CoversClass( TraversalRoute::class )]
final class TraversalRouteTest extends TestCase
{
    private function app( Container $container ) :App
    {
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;
        $container->set( App::class , $app ) ;
        return $app ;
    }

    public function testRegistersTheFourNavigationRoutes() :void
    {
        $container = new Container() ;
        $app       = $this->app( $container ) ;

        $controller = new class
        {
            public function getParent()      { return TraversalController::GET_PARENT      ; }
            public function getChildren()    { return TraversalController::GET_CHILDREN    ; }
            public function getAncestors()   { return TraversalController::GET_ANCESTORS   ; }
            public function getDescendants() { return TraversalController::GET_DESCENDANTS ; }
        } ;
        $container->set( 'categories.traversal' , $controller ) ;

        ( new TraversalRoute( $container ,
        [
            Route::CONTROLLER_ID => 'categories.traversal' ,
            Route::ROUTE         => 'thesaurus/products/categories' ,
        ]) )() ;

        $registered = $app->getRouteCollector()->getRoutes() ;
        $this->assertCount( 4 , $registered ) ;

        $bySuffix = [] ;
        foreach ( $registered as $route )
        {
            $this->assertSame( [ 'GET' ] , $route->getMethods() ) ;
            $pattern = $route->getPattern() ;
            $suffix  = substr( $pattern , (int) strrpos( $pattern , '/' ) + 1 ) ;
            $bySuffix[ $suffix ] = ( $route->getCallable() )() ;
        }

        $this->assertSame( TraversalController::GET_PARENT      , $bySuffix[ TraversalRoute::PARENT      ] ) ;
        $this->assertSame( TraversalController::GET_CHILDREN    , $bySuffix[ TraversalRoute::CHILDREN    ] ) ;
        $this->assertSame( TraversalController::GET_ANCESTORS   , $bySuffix[ TraversalRoute::ANCESTORS   ] ) ;
        $this->assertSame( TraversalController::GET_DESCENDANTS , $bySuffix[ TraversalRoute::DESCENDANTS ] ) ;
    }

    public function testDoesNothingWhenControllerNotRegistered() :void
    {
        $container = new Container() ;
        $app       = $this->app( $container ) ;

        ( new TraversalRoute( $container ,
        [
            Route::CONTROLLER_ID => 'missing.controller' ,
            Route::ROUTE         => 'thesaurus/products/categories' ,
        ]) )() ;

        $this->assertCount( 0 , $app->getRouteCollector()->getRoutes() ) ;
    }
}
