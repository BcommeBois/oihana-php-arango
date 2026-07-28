<?php

namespace oihana\arango\controllers;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Exception;

use oihana\arango\controllers\traits\AuthorizationContextTrait;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

use oihana\auth\controllers\traits\CapabilityContextTrait;
use oihana\auth\controllers\traits\PermissionAuthorizerTrait;

use oihana\controllers\Controller;
use oihana\controllers\traits\ModelCallTrait;
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

use function oihana\core\container\resolveDependency;

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
 * Like the other controllers, it carries an **authorization seat**: the model
 * calls are wrapped by the {@see ModelCallTrait} hooks, so a consumer can refuse
 * to link — or to unlink — documents its scope hides. Without that seat this was
 * both a write surface reaching outside any scope, and an existence oracle: the
 * three refusals (`404` source, `404` target, `409` edge exists) told a caller
 * what a scoped `GET` withholds.
 *
 * **One hook, three collections.** Unlike the other controllers this one talks to
 * three models — the source vertices, the target vertices, and the edges — and a
 * predicate written for one is meaningless on the others. Each hook call
 * therefore carries {@see self::CALL}, whose value is {@see self::FROM},
 * {@see self::TO} or {@see self::EDGES}, so an override knows which collection it
 * is scoping:
 *
 * ```php
 * protected function beforeModelCall( ?Request $request , array &$init ) : void
 * {
 *     if ( ( $init[ self::CALL ] ?? null ) === self::FROM )
 *     {
 *         $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , 'doc.status == @scope' ] ;
 *         $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , 'scope' => 'published' ] ;
 *     }
 *
 *     parent::beforeModelCall( $request , $init ) ;
 * }
 * ```
 *
 * **The creation itself is deliberately left unhooked.** An `INSERT` has no
 * `FOR` and no `FILTER`, so there is nothing to narrow — and worse,
 * {@see Edges::insertEdge()} forwards its `$init` to the `existEdge()` uniqueness
 * check: a scope posed there would blind the `409` and let a duplicate through.
 * A creation is refused upstream, by the two vertex probes, which **are** hooked.
 * The request-scoped authorizer is still posed on it, so the returned edge is
 * projected under the same `Field::REQUIRES` gates as a read.
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

        // Resolve the capability enforcer and permission-subject resolver from the
        // container so the vertex probes can be scoped and the projection gated
        // (fail-open when no authorization stack is wired).
        $this->initializeAuthorizationContext( $init ) ;
    }

    use AuthorizationContextTrait ,
        CapabilityContextTrait    ,
        ModelCallTrait            ,
        PermissionAuthorizerTrait ;

    /**
     * The init key naming the collection a hook call is about — its value is one
     * of {@see self::FROM}, {@see self::TO} or {@see self::EDGES}.
     */
    public const string CALL = 'call' ;

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

            // Validate source vertex exists — scoped, so a document the caller may
            // not see reads as absent rather than as a link it is allowed to make.
            if( $this->from && !$this->from->exist( $this->vertexInit( $request , $init , $id , self::FROM ) ) )
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
            if( $this->to && !$this->to->exist( $this->vertexInit( $request , $init , $targetKey , self::TO ) ) )
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

            // Create the edge. Deliberately NOT hooked: an INSERT has nothing to
            // narrow, and insertEdge() forwards this init to its existEdge()
            // uniqueness check — a scope posed here would blind the 409 and let a
            // duplicate through. Only the authorizer is posed, so the returned edge
            // is projected under the same gates as a read.
            $edge = $this->edges->insertEdge( $id , $targetKey , $doc , $this->authorized( $request , $init ) ) ;

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

            // Validate source vertex exists — scoped, like on the creation path.
            if( $this->from && !$this->from->exist( $this->vertexInit( $request , $init , $id , self::FROM ) ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : "Source document \"$id\" does not exist"
                ) ;
            }

            // The probe and the deletion share ONE init, so they cannot disagree:
            // an edge the scope hides is reported missing and is never removed —
            // no "404 on the probe, 200 on a deletion that touched nothing" gap.
            $edgeInit = [ ...$init , self::CALL => self::EDGES ] ;

            $this->beforeModelCall( $request , $edgeInit ) ;

            // Validate edge exists
            if( !$this->edges->existEdge( $id , $targetKey , $edgeInit ) )
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
            $result = $this->edges->deleteEdge( $id , $targetKey , $edgeInit ) ;

            $this->afterModelCall( $request , $edgeInit , $result ) ;

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

    /**
     * Injects the request-scoped permission authorizer into the model `$init`
     * before every hooked model call.
     *
     * Overrides the no-op {@see ModelCallTrait::beforeModelCall()}. Strictly the
     * behaviour of {@see DocumentsController::beforeModelCall()}, with the same two
     * guards — an authorizer already in the init wins, and nothing is posed without
     * an authorization stack or an authenticated user.
     *
     * A subclass is what turns the seat into an actual scope; it should branch on
     * {@see self::CALL} before appending anything, since the three hooked calls
     * target three different collections.
     *
     * @param Request|null        $request The current PSR-7 request.
     * @param array<string,mixed> $init    The init array forwarded to the model (by reference).
     *
     * @return void
     */
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        $init = $this->authorized( $request , $init ) ;
    }

    /**
     * The caller init carrying the request-scoped authorizer — the projection
     * gate, posed on the calls the consumer hook deliberately does not reach.
     *
     * An authorizer already supplied by the caller wins, and nothing is posed when
     * there is no request, no enforcer, no resolver or no authenticated user: the
     * projection then falls open, exactly as before the seat existed.
     *
     * @param Request|null        $request The current PSR-7 request.
     * @param array<string,mixed> $init    The caller init.
     *
     * @return array<string,mixed>
     */
    private function authorized( ?Request $request , array $init ) :array
    {
        if ( !array_key_exists( Arango::AUTHORIZER , $init ) && ( $authorizer = $this->buildPermissionAuthorizer( $request ) ) !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }

        return $init ;
    }

    /**
     * Builds the init of a vertex existence probe and runs the hook on it.
     *
     * @param Request|null        $request The current PSR-7 request.
     * @param array<string,mixed> $init    The caller init (definition-level conditions travel here).
     * @param string              $value   The probed document key.
     * @param string              $call    {@see self::FROM} or {@see self::TO}.
     *
     * @return array<string,mixed> The enriched probe init.
     */
    private function vertexInit( ?Request $request , array $init , string $value , string $call ) :array
    {
        $probeInit = [ ...$init , Arango::VALUE => $value , self::CALL => $call ] ;

        $this->beforeModelCall( $request , $probeInit ) ;

        return $probeInit ;
    }
}
