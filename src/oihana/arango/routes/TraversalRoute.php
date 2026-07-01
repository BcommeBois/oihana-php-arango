<?php

namespace oihana\arango\routes;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\enums\Char;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\controllers\TraversalController;

use oihana\routes\Route;
use oihana\routes\http\GetRoute;

use function oihana\core\arrays\clean;
use function oihana\routes\helpers\withPlaceholder;

/**
 * Declares, in a single definition, the four navigation sub-routes of a
 * {@see TraversalController} (which walks a self-referential edge — a category
 * tree, an org chart, a thread…).
 *
 * Given a base `route` of `/{collection}`, it registers, on the item URL
 * `/{collection}/{id}` :
 *
 * | Verb  | Path                          | Controller method   |
 * |-------|-------------------------------|---------------------|
 * | `GET` | `/{collection}/{id}/parent`      | `getParent`      |
 * | `GET` | `/{collection}/{id}/children`    | `getChildren`    |
 * | `GET` | `/{collection}/{id}/ancestors`   | `getAncestors`   |
 * | `GET` | `/{collection}/{id}/descendants` | `getDescendants` |
 *
 * The `{id}` placeholder is configurable via {@see Route::ROUTE_PLACEHOLDER}
 * (default `{id:[0-9]+}`). Method bindings use the {@see TraversalController}
 * constants (no magic strings). Register it **before** the generic document
 * route so the literal suffixes are matched first.
 *
 * ```php
 * // definitions/routes.php
 * Routes::CATEGORIES_TREE => fn( Container $c ) => new TraversalRoute( $c,
 * [
 *     Route::CONTROLLER_ID => Controllers::CATEGORIES_TRAVERSAL , // a TraversalController(edge: …)
 *     Route::ROUTE         => '/thesaurus/products/categories'  ,
 * ]) ,
 * ```
 *
 * @package oihana\arango\routes
 *
 * @see TraversalController
 * @see ArrayPropertyRoute
 * @see DocumentRoute
 */
class TraversalRoute extends Route
{
    /**
     * The `/ancestors` sub-route suffix.
     */
    public const string ANCESTORS = 'ancestors' ;

    /**
     * The `/children` sub-route suffix.
     */
    public const string CHILDREN = 'children' ;

    /**
     * The `/descendants` sub-route suffix.
     */
    public const string DESCENDANTS = 'descendants' ;

    /**
     * The `/parent` sub-route suffix.
     */
    public const string PARENT = 'parent' ;

    /**
     * Registers the four navigation sub-routes on the application.
     *
     * @return void
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     */
    public function __invoke() : void
    {
        if ( !$this->container->has( $this->controllerID ) )
        {
            $this->logger?->warning( $this . ' invoke failed, the controller \'' . $this->controllerID . '\' is not registered in the DI container.' ) ;
            return ;
        }

        $item = withPlaceholder( $this->getRoute() , $this->routePlaceholder ) ;

        $this->execute
        ([
            $this->subRoute( $item . Char::SLASH . self::PARENT      , TraversalController::GET_PARENT      ) ,
            $this->subRoute( $item . Char::SLASH . self::CHILDREN    , TraversalController::GET_CHILDREN    ) ,
            $this->subRoute( $item . Char::SLASH . self::ANCESTORS   , TraversalController::GET_ANCESTORS   ) ,
            $this->subRoute( $item . Char::SLASH . self::DESCENDANTS , TraversalController::GET_DESCENDANTS ) ,
        ]) ;
    }

    /**
     * Builds a single GET sub-route bound to the given controller method.
     *
     * @param string $route  The route pattern.
     * @param string $method The controller method name to bind.
     *
     * @return Route
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     */
    private function subRoute( string $route , string $method ) : Route
    {
        return new GetRoute( $this->container , clean
        ([
            Route::CONTROLLER_ID => $this->controllerID ,
            Route::METHOD        => $method ,
            Route::ROUTE         => $route ,
        ]) ) ;
    }
}
