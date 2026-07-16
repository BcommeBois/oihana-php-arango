<?php

namespace tests\oihana\arango\routes;

use DI\Container;

use oihana\arango\controllers\ArrayPropertyController;
use oihana\arango\routes\ArrayPropertyRoute;

use oihana\routes\Route;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Coverage for {@see ArrayPropertyRoute} — registers the four array sub-resource
 * routes of an {@see ArrayPropertyController}.
 *
 * @package tests\oihana\arango\routes
 * @author  Marc Alcaraz
 */
#[CoversClass( ArrayPropertyRoute::class )]
final class ArrayPropertyRouteTest extends TestCase
{
    private function app( Container $container ) :App
    {
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;
        $container->set( App::class , $app ) ;
        return $app ;
    }

    public function testRegistersTheFourArrayRoutes() :void
    {
        $container = new Container() ;
        $app       = $this->app( $container ) ;

        $controller = new class
        {
            public function addItem()    { return ArrayPropertyController::ADD_ITEM    ; }
            public function removeItem() { return ArrayPropertyController::REMOVE_ITEM ; }
            public function moveItem()   { return ArrayPropertyController::MOVE_ITEM   ; }
            public function hasItem()    { return ArrayPropertyController::HAS_ITEM    ; }
        } ;
        $container->set( 'playlist.tracks' , $controller ) ;

        ( new ArrayPropertyRoute( $container ,
        [
            Route::CONTROLLER_ID => 'playlist.tracks' ,
            Route::ROUTE         => 'playlists/{id}/tracks' ,
        ]) )() ;

        $registered = $app->getRouteCollector()->getRoutes() ;
        $this->assertCount( 4 , $registered ) ;

        $map = [] ;
        foreach ( $registered as $route )
        {
            $map[ implode( ',' , $route->getMethods() ) . ' ' . $route->getPattern() ] = ( $route->getCallable() )() ;
        }

        $this->assertSame( ArrayPropertyController::ADD_ITEM    , $map[ 'POST /playlists/{id}/tracks' ] ) ;
        $this->assertSame( ArrayPropertyController::REMOVE_ITEM , $map[ 'DELETE /playlists/{id}/tracks/{value}' ] ) ;
        $this->assertSame( ArrayPropertyController::MOVE_ITEM   , $map[ 'PATCH /playlists/{id}/tracks/{value}' ] ) ;
        $this->assertSame( ArrayPropertyController::HAS_ITEM    , $map[ 'GET /playlists/{id}/tracks/{value}' ] ) ;
    }

    public function testCustomValuePlaceholder() :void
    {
        $container = new Container() ;
        $app       = $this->app( $container ) ;

        $controller = new class { public function removeItem() {} public function addItem() {} public function moveItem() {} public function hasItem() {} } ;
        $container->set( 'playlist.tracks' , $controller ) ;

        ( new ArrayPropertyRoute( $container ,
        [
            Route::CONTROLLER_ID            => 'playlist.tracks' ,
            Route::ROUTE                    => 'playlists/{id}/tracks' ,
            ArrayPropertyRoute::VALUE_PLACEHOLDER => 'value:[^/]+' ,
        ]) )() ;

        $patterns = array_map( fn( $r ) => $r->getPattern() , $app->getRouteCollector()->getRoutes() ) ;
        $this->assertContains( '/playlists/{id}/tracks/{value:[^/]+}' , $patterns ) ;
    }

    public function testSkipsWhenControllerIsNotRegistered() :void
    {
        $container = new Container() ;
        $app       = $this->app( $container ) ;

        ( new ArrayPropertyRoute( $container ,
        [
            Route::CONTROLLER_ID => 'absent.controller' ,
            Route::ROUTE         => 'playlists/{id}/tracks' ,
        ]) )() ;

        $this->assertCount( 0 , $app->getRouteCollector()->getRoutes() ) ;
    }
}
