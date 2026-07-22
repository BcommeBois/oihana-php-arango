<?php

namespace oihana\arango\controllers;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\controllers\traits\AuthorizationContextTrait;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\Edges;

use oihana\auth\controllers\traits\CapabilityContextTrait;
use oihana\auth\controllers\traits\PermissionAuthorizerTrait;

use oihana\controllers\Controller;
use oihana\controllers\traits\prepare\PrepareFilter;

use oihana\enums\Char;
use oihana\enums\Output;
use oihana\enums\http\HttpStatusCode;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;

use org\schema\constants\Schema;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use ReflectionException;

use function oihana\core\container\resolveDependency;

/**
 * Navigates a **self-referential** edge (a graph whose two ends target the same
 * vertex collection, e.g. a category tree or an org chart) and returns the
 * traversed vertices, hydrated with the target collection's schema.
 *
 * A single instance exposes the four navigation methods — designed to be wired,
 * one route each, by a {@see \oihana\arango\routes\TraversalRoute} on a base
 * `/{collection}/{id}` path:
 *
 * | Method             | Direction | Transitive | Cardinality | Typical route  |
 * |--------------------|-----------|------------|-------------|----------------|
 * | {@see getParent}      | INBOUND   | no         | one/null    | `/{id}/parent`      |
 * | {@see getChildren}    | OUTBOUND  | no         | list        | `/{id}/children`    |
 * | {@see getAncestors}   | INBOUND   | yes        | list        | `/{id}/ancestors`   |
 * | {@see getDescendants} | OUTBOUND  | yes        | list        | `/{id}/descendants` |
 *
 * The edge is injected once through {@see self::EDGE}. The transitive methods
 * accept a `?depth=` query parameter, clamped to {@see self::DEFAULT_MAX_DEPTH}
 * (defaulting to the full sub-tree). Vertices hydrate through the edge's target
 * model (`Edges::get*Vertices()`), so a projected field survives the traversal.
 *
 * @package oihana\arango\controllers
 * @author  Marc Alcaraz
 */
class TraversalController extends Controller
{
    /**
     * Creates a new TraversalController instance.
     *
     * @param Container $container The DI container reference.
     * @param array $init Supports {@see self::EDGE} : the self-referential `Edges`
     *                    model (service id or instance).
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

        $edges       = resolveDependency( $init[ self::EDGE ] ?? null , $container ) ;
        $this->edges = $edges instanceof Edges ? $edges : null ;

        // Resolve the capability enforcer and permission-subject resolver from the
        // container so `?filter=` can be gated by the request authorizer (fail-open
        // when no authorization stack is wired).
        $this->initializeAuthorizationContext( $init ) ;
    }

    use AuthorizationContextTrait ,
        CapabilityContextTrait    ,
        PermissionAuthorizerTrait ,
        PrepareFilter             ;

    /**
     * Initialization key for the self-referential edge model.
     */
    public const string EDGE = 'edge' ;

    /**
     * The `?depth=` query-parameter name capping a transitive traversal.
     */
    public const string DEPTH_PARAM = 'depth' ;

    /**
     * The hard cap on the traversal depth.
     */
    public const int DEFAULT_MAX_DEPTH = 16 ;

    /**
     * The name of the {@see getParent} method — for a route method binding.
     */
    public const string GET_PARENT = 'getParent' ;

    /**
     * The name of the {@see getChildren} method — for a route method binding.
     */
    public const string GET_CHILDREN = 'getChildren' ;

    /**
     * The name of the {@see getAncestors} method — for a route method binding.
     */
    public const string GET_ANCESTORS = 'getAncestors' ;

    /**
     * The name of the {@see getDescendants} method — for a route method binding.
     */
    public const string GET_DESCENDANTS = 'getDescendants' ;

    /**
     * The self-referential edges model.
     */
    protected ?Edges $edges = null ;

    /**
     * Returns the **ancestors** lineage up to the root (INBOUND, transitive).
     *
     * @param Request|null $request
     * @param Response|null $response
     * @param array $args Route placeholders — expects {@see Schema::ID}.
     * @param array $init
     *
     * @return mixed
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function getAncestors( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        return $this->many( $request , $response , $args , inbound: true , transitive: true , init: $init ) ;
    }

    /**
     * Returns the direct **children** vertices (OUTBOUND).
     *
     * @param Request|null $request
     * @param Response|null $response
     * @param array $args Route placeholders — expects {@see Schema::ID}.
     * @param array $init
     *
     * @return mixed
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function getChildren( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        return $this->many( $request , $response , $args , inbound: false , transitive: false , init: $init ) ;
    }

    /**
     * Returns the full **descendants** sub-tree (OUTBOUND, transitive).
     *
     * @param Request|null $request
     * @param Response|null $response
     * @param array $args Route placeholders — expects {@see Schema::ID}.
     * @param array $init
     *
     * @return mixed
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function getDescendants( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        return $this->many( $request , $response , $args , inbound: false , transitive: true , init: $init ) ;
    }

    /**
     * Returns the single **parent** vertex (INBOUND, direct), or `null` for a root.
     *
     * @param Request|null $request
     * @param Response|null $response
     * @param array $args Route placeholders — expects {@see Schema::ID}.
     * @param array $init
     *
     * @return mixed
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function getParent( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) :mixed
    {
        return $this->single( $request , $response , $args , inbound: true , init: $init ) ;
    }

    /**
     * Resolves the `{id}` placeholder, or fails.
     */
    private function id( array $args ) :?string
    {
        $id = $args[ Schema::ID ] ?? null ;
        return ( is_string( $id ) && $id !== Char::EMPTY ) ? $id : null ;
    }

    /**
     * Backs the list methods (children / ancestors / descendants).
     *
     * @param Request|null $request
     * @param Response|null $response
     * @param array $args
     * @param bool $inbound
     * @param bool $transitive
     * @param array $init
     *
     * @return mixed
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function many( ?Request $request , ?Response $response , array $args , bool $inbound , bool $transitive , array $init = [] ) :mixed
    {
        if ( ( $id = $this->id( $args ) ) === null )
        {
            return $this->fail( $request , $response , HttpStatusCode::BAD_REQUEST , 'Missing id' ) ;
        }

        if ( !$this->edges )
        {
            return $this->fail( $request , $response , HttpStatusCode::INTERNAL_SERVER_ERROR , 'Traversal edge not configured' ) ;
        }

        $modelInit = [] ;

        if ( $transitive )
        {
            $param = $request?->getQueryParams()[ self::DEPTH_PARAM ] ?? null ;
            $depth = is_numeric( $param ) ? (int) $param : 0 ;
            // A MAX_DEPTH without a MIN_DEPTH yields an invalid `IN ..N` traversal.
            $modelInit[ AQL::MIN_DEPTH ] = 1 ;
            $modelInit[ AQL::MAX_DEPTH ] = $depth > 0 ? min( $depth , self::DEFAULT_MAX_DEPTH ) : self::DEFAULT_MAX_DEPTH ;
        }

        $modelInit = $this->prepareVertexFilter( $request , $init , $modelInit ) ;

        $vertices = $inbound
                  ? $this->edges->getInboundVertices ( $id , $modelInit )
                  : $this->edges->getOutboundVertices( $id , $modelInit ) ;

        $vertices = is_array( $vertices ) ? $vertices : [] ;

        // Not paginated : all traversed vertices ship in one response, so count == total.
        $total = count( $vertices ) ;

        return $this->success( $request , $response , $vertices , [ Output::COUNT => $total , Output::TOTAL => $total ] ) ;
    }

    /**
     * Reads the client `?filter=` (a JSON predicate, the same DSL as the
     * `Documents` surface) and folds it into the traversal `$modelInit` — as an
     * AQL fragment plus its binds — targeting the traversed `vertex`.
     *
     * The filter never reaches the traversal raw : `getVertices()` inlines its
     * `AQL::FILTER` slot verbatim (a server-only knob), so the JSON is first
     * **compiled** by the edge model's gated engine ({@see Edges::prepareFilter()}),
     * which applies the model's `AQL::FILTERS` whitelist and, through the request
     * authorizer, the `Field::REQUIRES` gate. An undeclared or hidden attribute
     * therefore cannot be probed through the traversal (no filter oracle).
     *
     * The same request-scoped authorizer is threaded under `Arango::AUTHORIZER`
     * so the vertex projection ({@see Edges::returnFields()}) is gated too — but
     * only when one exists : with no authorization stack it stays absent and the
     * projection falls open (backward compatible).
     *
     * @param Request|null $request The current PSR-7 request.
     * @param array $init The caller init (an `Arango::AUTHORIZER` here wins).
     * @param array $modelInit The traversal init being assembled.
     *
     * @return array The `$modelInit` enriched with `AQL::FILTER` / `AQL::BINDS` /
     *               `Arango::AUTHORIZER` when applicable.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function prepareVertexFilter( ?Request $request , array $init , array $modelInit ) :array
    {
        $authorizer = $init[ Arango::AUTHORIZER ] ?? $this->buildPermissionAuthorizer( $request ) ;

        if ( $authorizer !== null )
        {
            $modelInit[ Arango::AUTHORIZER ] = $authorizer ;
        }

        $params    = [] ;
        $urlFilter = $this->prepareFilter( $request , $init , $params ) ;

        if ( $urlFilter === null )
        {
            return $modelInit ;
        }

        // Compile the JSON predicate against the traversed `vertex` variable
        // (matching aqlTraversal's `FOR vertex …` and getVertices' `RETURN vertex`).
        $binds  = [] ;
        $filter = $this->edges->prepareFilter
        (
            [ Arango::FILTER => $urlFilter , Arango::AUTHORIZER => $authorizer ] ,
            $binds ,
            AQL::VERTEX
        ) ;

        if ( $filter !== null )
        {
            $modelInit[ AQL::FILTER ] = $filter ;

            if ( $binds !== [] )
            {
                $modelInit[ AQL::BINDS ] = $binds ;
            }
        }

        return $modelInit ;
    }

    /**
     * Backs the single-vertex methods (the direct parent).
     *
     * @param Request|null $request
     * @param Response|null $response
     * @param array $args
     * @param bool $inbound
     * @param array $init
     * @return mixed
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function single( ?Request $request , ?Response $response , array $args , bool $inbound , array $init = [] ) :mixed
    {
        if ( ( $id = $this->id( $args ) ) === null )
        {
            return $this->fail( $request , $response , HttpStatusCode::BAD_REQUEST , 'Missing id' ) ;
        }

        if ( !$this->edges )
        {
            return $this->fail( $request , $response , HttpStatusCode::INTERNAL_SERVER_ERROR , 'Traversal edge not configured' ) ;
        }

        $modelInit = $this->prepareVertexFilter( $request , $init , [] ) ;

        $vertex = $inbound ? $this->edges->getFirstInboundVertex ( $id , $modelInit ) : $this->edges->getFirstOutboundVertex( $id , $modelInit ) ;

        return $this->success( $request , $response , $vertex ?: null ) ;
    }
}
