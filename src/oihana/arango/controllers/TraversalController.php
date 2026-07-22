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

use function oihana\arango\db\operators\logicalNot;
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
 * All four methods accept a `?filter=` predicate (the same JSON DSL as the
 * `Documents` surface) restricting the traversed vertices, and `?prune=` cutting
 * the whole branch under a non-matching vertex (rejected on the inbound methods).
 * Both are compiled by the edge model's gated engine — whitelist + authorizer —
 * never inlined raw.
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
     * The `?prune=` query-parameter name — a JSON predicate that cuts the whole
     * branch under a non-matching vertex. Rejected on inbound traversals.
     */
    public const string PRUNE_PARAM = 'prune' ;

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

        $prune = $this->readPrune( $request ) ;

        if ( $prune !== null && $inbound )
        {
            return $this->fail( $request , $response , HttpStatusCode::BAD_REQUEST , 'The ?prune= parameter is not supported on inbound traversals' ) ;
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

        $modelInit = $this->prepareVertexFilter( $request , $init , $modelInit , $prune ) ;

        $vertices = $inbound
                  ? $this->edges->getInboundVertices ( $id , $modelInit )
                  : $this->edges->getOutboundVertices( $id , $modelInit ) ;

        $vertices = is_array( $vertices ) ? $vertices : [] ;

        // Not paginated : all traversed vertices ship in one response, so count == total.
        $total = count( $vertices ) ;

        return $this->success( $request , $response , $vertices , [ Output::COUNT => $total , Output::TOTAL => $total ] ) ;
    }

    /**
     * Folds the client `?filter=` and `?prune=` predicates (both a JSON predicate,
     * the same DSL as the `Documents` surface) into the traversal `$modelInit` —
     * as AQL fragments plus their binds — targeting the traversed `vertex`.
     *
     * Neither predicate reaches the traversal raw : `getVertices()` inlines its
     * `AQL::FILTER` slot, and `aqlTraversal()` its `AQL::PRUNE` slot, **verbatim**
     * (server-only knobs), so the JSON is first **compiled** by the edge model's
     * gated engine ({@see Edges::prepareFilter()}), which applies the model's
     * `AQL::FILTERS` whitelist and, through the request authorizer, the
     * `Field::REQUIRES` gate. An undeclared or hidden attribute therefore cannot be
     * probed through the traversal (no filter oracle).
     *
     * The two levers differ only on the descent:
     * - `?filter=` **hides** a non-matching vertex but the traversal still descends
     *   through it (a matching grand-child survives a filtered parent) ;
     * - `?prune=` **cuts** the whole branch under a non-matching vertex — the
     *   boundary vertex is excluded too (its condition also joins the `FILTER`) and
     *   its sub-tree is never walked (`PRUNE !( condition )`).
     *
     * They compose : every condition narrows the returned set (they `AND` in the
     * `FILTER`), and only the prune one also stops the descent.
     *
     * The same request-scoped authorizer is threaded under `Arango::AUTHORIZER`
     * so the vertex projection ({@see Edges::returnFields()}) is gated too — but
     * only when one exists : with no authorization stack it stays absent and the
     * projection falls open (backward compatible).
     *
     * @param Request|null $request   The current PSR-7 request.
     * @param array        $init      The caller init (an `Arango::AUTHORIZER` here wins).
     * @param array        $modelInit The traversal init being assembled.
     * @param array|null   $prune     The decoded `?prune=` predicate, or null.
     *
     * @return array The `$modelInit` enriched with `AQL::FILTER` / `AQL::PRUNE` /
     *               `AQL::BINDS` / `Arango::AUTHORIZER` when applicable.
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
    private function prepareVertexFilter( ?Request $request , array $init , array $modelInit , ?array $prune = null ) :array
    {
        $authorizer = $init[ Arango::AUTHORIZER ] ?? $this->buildPermissionAuthorizer( $request ) ;

        if ( $authorizer !== null )
        {
            $modelInit[ Arango::AUTHORIZER ] = $authorizer ;
        }

        $conditions = [] ;
        $binds      = [] ;

        // ?filter= : hide a non-matching vertex, but keep descending through it.
        $params    = [] ;
        $urlFilter = $this->prepareFilter( $request , $init , $params ) ;

        if ( $urlFilter !== null )
        {
            $fragment = $this->compileVertexPredicate( $urlFilter , $authorizer , $binds ) ;

            if ( $fragment !== null )
            {
                $conditions[] = $fragment ;
            }
        }

        // ?prune= : cut the whole branch under a non-matching vertex — exclude the
        // boundary too (FILTER) and never walk its sub-tree (PRUNE on the negation).
        if ( $prune !== null )
        {
            $fragment = $this->compileVertexPredicate( $prune , $authorizer , $binds ) ;

            if ( $fragment !== null )
            {
                $conditions[]            = $fragment ;
                $modelInit[ AQL::PRUNE ] = logicalNot( $fragment , true ) ;
            }
        }

        if ( $conditions !== [] )
        {
            $modelInit[ AQL::FILTER ] = count( $conditions ) === 1 ? $conditions[ 0 ] : $conditions ;
        }

        if ( $binds !== [] )
        {
            $modelInit[ AQL::BINDS ] = $binds ;
        }

        return $modelInit ;
    }

    /**
     * Compiles one JSON predicate into an AQL fragment against the traversed
     * `vertex` variable (matching `aqlTraversal`'s `FOR vertex …` and
     * `getVertices`' `RETURN vertex`), accumulating its binds into `$binds`.
     *
     * Returns null when nothing is filterable — an undeclared attribute or an
     * empty `AQL::FILTERS` whitelist — so the caller drops the fragment.
     *
     * @param array $predicate  The decoded JSON predicate.
     * @param mixed $authorizer The request-scoped authorizer (or null).
     * @param array $binds      The accumulating bind variables (by reference).
     *
     * @return string|null The compiled `vertex.<field> …` fragment, or null.
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
    private function compileVertexPredicate( array $predicate , mixed $authorizer , array &$binds ) :?string
    {
        $local    = [] ;
        $fragment = $this->edges->prepareFilter
        (
            [ Arango::FILTER => $predicate , Arango::AUTHORIZER => $authorizer ] ,
            $local ,
            AQL::VERTEX
        ) ;

        if ( $fragment !== null && $local !== [] )
        {
            $binds = [ ...$binds , ...$local ] ;
        }

        return $fragment ;
    }

    /**
     * Reads and decodes the `?prune=` query parameter (a JSON predicate).
     *
     * Malformed or non-array JSON degrades to null (no prune), mirroring the
     * `?filter=` reader.
     *
     * @param Request|null $request The current PSR-7 request.
     *
     * @return array|null The decoded predicate, or null.
     */
    private function readPrune( ?Request $request ) :?array
    {
        $param = $request?->getQueryParams()[ self::PRUNE_PARAM ] ?? null ;

        if ( is_string( $param ) && json_validate( $param ) )
        {
            $value = json_decode( $param , true ) ;

            return is_array( $value ) ? $value : null ;
        }

        return null ;
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

        $prune = $this->readPrune( $request ) ;

        if ( $prune !== null && $inbound )
        {
            return $this->fail( $request , $response , HttpStatusCode::BAD_REQUEST , 'The ?prune= parameter is not supported on inbound traversals' ) ;
        }

        $modelInit = $this->prepareVertexFilter( $request , $init , [] , $prune ) ;

        $vertex = $inbound ? $this->edges->getFirstInboundVertex ( $id , $modelInit ) : $this->edges->getFirstOutboundVertex( $id , $modelInit ) ;

        return $this->success( $request , $response , $vertex ?: null ) ;
    }
}
