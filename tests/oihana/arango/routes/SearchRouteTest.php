<?php

namespace tests\oihana\arango\routes ;

use DI\Container ;

use oihana\arango\routes\SearchRoute ;

use oihana\routes\Route ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

use Slim\App ;
use Slim\Factory\AppFactory ;

/**
 * Coverage for {@see SearchRoute} — registers the single read-only `GET` route
 * bound to a controller's `search` action.
 *
 * @package tests\oihana\arango\routes
 * @author  Marc Alcaraz (ekameleon)
 */
#[CoversClass( SearchRoute::class )]
final class SearchRouteTest extends TestCase
{
    private function app( Container $container ) :App
    {
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;
        $container->set( App::class , $app ) ;
        return $app ;
    }

    public function testRegistersTheSearchRoute() :void
    {
        $container = new Container() ;
        $app       = $this->app( $container ) ;

        $controller = new class
        {
            public function search() {}
        } ;
        $container->set( 'search.controller' , $controller ) ;

        ( new SearchRoute( $container ,
        [
            Route::CONTROLLER_ID => 'search.controller' ,
            Route::ROUTE         => 'search' ,
        ]) )() ;

        $registered = $app->getRouteCollector()->getRoutes() ;
        $this->assertCount( 1 , $registered ) ;

        $route = $registered[ array_key_first( $registered ) ] ;
        $this->assertSame( [ 'GET' ] , $route->getMethods() ) ;
        $this->assertSame( '/search' , $route->getPattern() ) ;
        $this->assertSame( SearchRoute::INTERNAL_METHOD , $route->getCallable()[ 1 ] ) ;
    }

    public function testDefaultsToTheSearchMethod() :void
    {
        $container = new Container() ;
        $this->app( $container ) ;

        $route = new SearchRoute( $container ,
        [
            Route::CONTROLLER_ID => 'search.controller' ,
            Route::ROUTE         => 'search' ,
        ]) ;

        $this->assertSame( 'search' , $route->method ) ;
    }

    public function testSkipsWhenTheControllerIsNotRegistered() :void
    {
        $container = new Container() ;
        $app       = $this->app( $container ) ;

        ( new SearchRoute( $container ,
        [
            Route::CONTROLLER_ID => 'missing.controller' ,
            Route::ROUTE         => 'search' ,
        ]) )() ;

        $this->assertCount( 0 , $app->getRouteCollector()->getRoutes() ) ;
    }
}
