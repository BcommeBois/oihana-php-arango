<?php

namespace tests\oihana\arango\controllers;

use DI\Container;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\controllers\EdgesController;
use oihana\controllers\enums\ControllerParam;

use Psr\Http\Message\ServerRequestInterface as Request;

use Psr\Http\Message\ResponseInterface as Response;

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Shared harness for the Arango controllers.
 *
 * Builds a real {@see DocumentsController} / {@see EdgesController} over a real
 * (but minimal) Slim app + DI container, wiring a {@see MockDocuments} /
 * {@see MockEdges} as the model. Handlers are then exercised through their
 * public API with `request`/`response` left null — `StatusTrait::success()`
 * returns the raw data directly in that case, so the whole PSR-7 response
 * machinery is bypassed and assertions target the controller's own logic
 * (model init building, gating, branch selection).
 *
 * No DI container entry for `CapabilityEnforcerInterface` is registered, so
 * the capability gating stays OFF (the base prepare* fallbacks run).
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
abstract class ControllerTestCase extends TestCase
{
    /**
     * Builds a DocumentsController backed by the given model double.
     *
     * @param MockDocuments $model The model double.
     * @param array         $init  Extra init overrides merged into the defaults.
     *
     * @return DocumentsController
     */
    protected function makeDocumentsController( MockDocuments $model , array $init = [] ) :DocumentsController
    {
        return new DocumentsController( ...$this->controllerArgs( $model , $init ) ) ;
    }

    /**
     * Builds an EdgesController backed by the given edge model double.
     *
     * @param MockEdges $model The edge model double.
     * @param array     $init  Extra init overrides merged into the defaults.
     *
     * @return EdgesController
     */
    protected function makeEdgesController( MockEdges $model , array $init = [] ) :EdgesController
    {
        return new EdgesController( ...$this->controllerArgs( $model , $init ) ) ;
    }

    /**
     * A PSR-7 GET server request carrying the given query params.
     *
     * @param array  $query  The query-string parameters.
     * @param string $method The HTTP method.
     * @param string $uri    The request URI.
     *
     * @return Request
     */
    protected function makeRequest( array $query = [] , string $method = 'GET' , string $uri = '/' ) :Request
    {
        $request = ( new ServerRequestFactory() )->createServerRequest( $method , $uri ) ;
        return $request->withQueryParams( $query ) ;
    }

    /**
     * A fresh PSR-7 response (used when a handler branch returns the response
     * object itself rather than the raw data — e.g. validation short-circuits).
     *
     * @return Response
     */
    protected function makeResponse() :Response
    {
        return ( new ResponseFactory() )->createResponse() ;
    }

    /**
     * The [ container , init ] argument pair shared by the controller factories.
     *
     * @param object $model The model double.
     * @param array  $init  Extra init overrides.
     *
     * @return array{0:Container,1:array}
     */
    private function controllerArgs( object $model , array $init ) :array
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $defaults =
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => $model ,
        ] ;

        return [ $container , [ ...$defaults , ...$init ] ] ;
    }
}
