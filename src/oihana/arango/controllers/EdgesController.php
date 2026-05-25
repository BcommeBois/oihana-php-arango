<?php

namespace oihana\arango\controllers;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Exception;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;
use oihana\controllers\Controller;
use oihana\enums\http\HttpStatusCode;
use oihana\enums\Output;
use oihana\exceptions\http\Error409;

use org\schema\constants\Schema;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use ReflectionException;

use Throwable;

use function oihana\controllers\helpers\resolveDependency;

/**
 * Generic controller for managing edge relationships between two vertex collections.
 *
 * Provides `post()` and `delete()` methods to create and remove edges
 * between a source vertex (from) and a target vertex (to).
 *
 * Both source and target vertex IDs are read from the URL route placeholders:
 * - `{id}` for the source vertex (Schema::ID)
 * - `{targetId}` for the target vertex
 *
 * POST also accepts an optional body for edge properties.
 *
 * @package oihana\arango\controllers
 * @author  Marc Alcaraz
 */
class EdgesController extends Controller
{
    /**
     * Creates a new EdgesController instance.
     *
     * @param Container $container The DI container reference.
     * @param array     $init      Supports:
     *                             - {@see self::EDGES}: Edges model service ID or instance
     *                             - {@see self::FROM}: Documents model for the source vertex
     *                             - {@see self::TO}: Documents model for the target vertex
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;

        $this->edges = resolveDependency( $init[ self::EDGES ] ?? null , $container ) ;
        $this->from  = resolveDependency( $init[ self::FROM  ] ?? null , $container ) ;
        $this->to    = resolveDependency( $init[ self::TO    ] ?? null , $container ) ;
    }

    /**
     * Initialization key for the Edges model dependency.
     */
    public const string EDGES = 'edges' ;

    /**
     * Initialization key for the source vertex Documents model.
     */
    public const string FROM = 'from' ;

    /**
     * Initialization key for the target vertex Documents model.
     */
    public const string TO = 'to' ;

    /**
     * URL placeholder name for the target vertex ID.
     */
    public const string TARGET_ID = 'targetId' ;

    /**
     * The Edges model for the edge collection.
     */
    protected ?Edges $edges = null ;

    /**
     * The Documents model for the source vertex collection.
     */
    protected ?Documents $from = null ;

    /**
     * The Documents model for the target vertex collection.
     */
    protected ?Documents $to = null ;

    /**
     * Creates a new edge between two vertices.
     *
     * Reads both vertex IDs from the route placeholders:
     * - `{id}` for the source vertex
     * - `{targetId}` for the target vertex
     *
     * The request body is optional and can contain additional edge properties.
     *
     * @param Request|null $request The PSR-7 request object.
     * @param Response|null $response The PSR-7 response object.
     * @param array $args Route placeholders (expects Schema::ID and self::TARGET_ID).
     * @param array $init Optional settings.
     *
     * @return mixed 201 on success, 400 if missing data, 404 if vertex not found, 409 if edge exists.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function post
    (
        ?Request  $request  = null ,
        ?Response $response = null ,
        array     $args     = []   ,
        array     $init     = []
    )
    :mixed
    {
        try
        {
            $id        = $args[ Schema::ID      ] ?? null ;
            $targetKey = $args[ self::TARGET_ID ] ?? null ;

            // Validate required parameters
            if( empty( $id ) || empty( $targetKey ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::BAD_REQUEST ,
                    details  : 'Missing source ID or target ID'
                ) ;
            }

            // Validate source vertex exists
            if( $this->from && !$this->from->exist([ Arango::VALUE => $id ]) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : "Source document \"$id\" does not exist"
                ) ;
            }

            // Validate target vertex exists
            if( $this->to && !$this->to->exist([ Arango::VALUE => $targetKey ]) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : "Target document \"$targetKey\" does not exist"
                ) ;
            }

            // Optional edge properties from the request body
            $doc = $request?->getParsedBody() ;
            $doc = is_array( $doc ) ? $doc : [] ;

            // Create the edge
            $edge = $this->edges->insertEdge( $id , $targetKey , $doc ) ;

            return $this->success( $request , $response , $edge , [ Output::STATUS => HttpStatusCode::CREATED ] ) ;
        }
        catch( Error409 )
        {
            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : HttpStatusCode::CONFLICT ,
                details  : 'Edge already exists'
            ) ;
        }
        catch( Exception $e )
        {
            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : HttpStatusCode::fromException( $e ) ,
                details  : $e->getMessage()
            ) ;
        }
    }

    /**
     * Removes an edge between two vertices.
     *
     * Reads both vertex IDs from the route placeholders:
     * - `{id}` for the source vertex
     * - `{targetId}` for the target vertex
     *
     * @param Request|null $request The PSR-7 request object.
     * @param Response|null $response The PSR-7 response object.
     * @param array $args Route placeholders (expects Schema::ID and self::TARGET_ID).
     * @param array $init Optional settings.
     *
     * @return mixed 200 on success, 404 if vertex or edge not found.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function delete
    (
        ?Request  $request  = null ,
        ?Response $response = null ,
        array     $args     = []   ,
        array     $init     = []
    )
    :mixed
    {
        try
        {
            $id        = $args[ Schema::ID      ] ?? null ;
            $targetKey = $args[ self::TARGET_ID ] ?? null ;

            // Validate required parameters
            if( empty( $id ) || empty( $targetKey ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::BAD_REQUEST ,
                    details  : 'Missing source ID or target ID'
                ) ;
            }

            // Validate source vertex exists
            if( $this->from && !$this->from->exist([ Arango::VALUE => $id ]) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : "Source document \"$id\" does not exist"
                ) ;
            }

            // Validate edge exists
            if( !$this->edges->existEdge( $id , $targetKey ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : "Edge between \"$id\" and \"$targetKey\" does not exist"
                ) ;
            }

            // Delete the edge
            $result = $this->edges->deleteEdge( $id , $targetKey ) ;

            return $this->success( $request , $response , $result ) ;
        }
        catch( Exception $e )
        {
            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : HttpStatusCode::fromException( $e ) ,
                details  : $e->getMessage()
            ) ;
        }
    }
}
