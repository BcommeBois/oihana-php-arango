<?php

namespace oihana\arango\routes;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\controllers\ArrayPropertyController;

use oihana\routes\Route;
use oihana\routes\http\DeleteRoute;
use oihana\routes\http\GetRoute;
use oihana\routes\http\PatchRoute;
use oihana\routes\http\PostRoute;

use function oihana\core\arrays\clean;
use function oihana\routes\helpers\withPlaceholder;

/**
 * Declares, in a single definition, the four REST sub-resource routes of an
 * {@see ArrayPropertyController} (which exposes the element-level operations of an
 * embedded array property).
 *
 * Given a base `route` of `/{collection}/{id}/{property}`, it registers:
 *
 * | Verb     | Path                                    | Controller method |
 * |----------|-----------------------------------------|-------------------|
 * | `POST`   | `/{collection}/{id}/{property}`         | `addItem`         |
 * | `DELETE` | `/{collection}/{id}/{property}/{value}` | `removeItem`      |
 * | `PATCH`  | `/{collection}/{id}/{property}/{value}` | `moveItem`        |
 * | `GET`    | `/{collection}/{id}/{property}/{value}` | `hasItem`         |
 *
 * The `{value}` placeholder is configurable via {@see self::VALUE_PLACEHOLDER}
 * (default `value`). Method bindings use the {@see ArrayPropertyController} constants
 * (no magic strings).
 *
 * ```php
 * // definitions/routes.php
 * Routes::PLAYLIST_TRACKS => fn( Container $c ) => new ArrayPropertyRoute( $c,
 * [
 *     Route::CONTROLLER_ID => Controllers::PLAYLIST_TRACKS , // an ArrayPropertyController(property: 'tracks')
 *     Route::ROUTE         => '/playlists/{id}/tracks'      ,
 * ]) ,
 * ```
 *
 * @package oihana\arango\routes
 *
 * @see ArrayPropertyController
 * @see DocumentRoute
 */
class ArrayPropertyRoute extends Route
{
    /**
     * Creates a new ArrayPropertyRoute instance.
     *
     * @param Container $container The DI container.
     * @param array $init The route initialization options (see {@see Route}), plus 
     *                    {@see self::VALUE_PLACEHOLDER} to customize the `{value}` segment.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     */
    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;
        $this->valuePlaceholder = $init[ self::VALUE_PLACEHOLDER ] ?? $this->valuePlaceholder ;
    }

    /**
     * The init key customizing the `{value}` placeholder (name, optionally with a regex constraint).
     */
    public const string VALUE_PLACEHOLDER = 'valuePlaceholder' ;

    /**
     * The `{value}` placeholder (a Slim segment name, optionally with a regex), default `value`.
     */
    public string $valuePlaceholder = 'value' ;

    /**
     * Registers the four array sub-resource routes on the application.
     *
     * @return void
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke() : void
    {
        if ( !$this->container->has( $this->controllerID ) )
        {
            $this->logger?->warning( $this . ' invoke failed, the controller \'' . $this->controllerID . '\' is not registered in the DI container.' ) ;
            return ;
        }

        $route = $this->getRoute() ;
        $item  = withPlaceholder( $route , $this->valuePlaceholder ) ;

        $this->execute
        ([
            $this->itemRoute( PostRoute::class   , $route , ArrayPropertyController::ADD_ITEM    ) ,
            $this->itemRoute( DeleteRoute::class , $item  , ArrayPropertyController::REMOVE_ITEM ) ,
            $this->itemRoute( PatchRoute::class  , $item  , ArrayPropertyController::MOVE_ITEM   ) ,
            $this->itemRoute( GetRoute::class    , $item  , ArrayPropertyController::HAS_ITEM    ) ,
        ]) ;
    }

    /**
     * Builds a single sub-resource route bound to the given controller method.
     *
     * @param string $clazz  The {@see Route} subclass (PostRoute, DeleteRoute, …).
     * @param string $route  The route pattern.
     * @param string $method The controller method name to bind.
     *
     * @return Route
     */
    private function itemRoute( string $clazz , string $route , string $method ) : Route
    {
        return new $clazz( $this->container , clean
        ([
            Route::CONTROLLER_ID => $this->controllerID ,
            Route::METHOD        => $method ,
            Route::ROUTE         => $route ,
        ]) ) ;
    }
}
