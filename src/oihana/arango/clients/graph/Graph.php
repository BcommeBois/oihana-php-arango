<?php

namespace oihana\arango\clients\graph ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\HttpException ;

use function oihana\arango\clients\helpers\unwrapField ;

/**
 * Operations scoped to a single named graph (gharial) on the server.
 *
 * Instances are obtained through {@see Database::graph()} or
 * {@see Database::createGraph()}. The graph name is fixed at
 * construction time and is interpolated into the
 * `/_api/gharial/{name}/...` routes by the helpers below.
 *
 * The class covers the graph lifecycle, the membership of vertex
 * collections, and the management of edge definitions — i.e.
 * everything that touches the graph's structure on the server.
 * Per-vertex and per-edge CRUD (insert / get / replace / update /
 * remove) lands separately on the {@see GraphVertexCollection} and
 * {@see GraphEdgeCollection} classes (Lot 7.1b).
 *
 * Example:
 * ```php
 * $employs = new EdgeDefinition
 * (
 *     collection : 'employs' ,
 *     from       : [ 'companies' ] ,
 *     to         : [ 'people' ] ,
 * ) ;
 *
 * $graph = $db->createGraph( 'workplaces' , [ $employs ] ) ;
 *
 * $graph->addVertexCollection( 'departments' ) ;
 * $graph->addEdgeDefinition
 * (
 *     new EdgeDefinition( 'reports_to' , [ 'people' ] , [ 'people' ] ) ,
 * ) ;
 *
 * $graph->drop( dropCollections : true ) ; // also drops vertex/edge collections
 * ```
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/graphs/named-graphs/
 *
 * @package oihana\arango\clients\graph
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class Graph
{
    /**
     * @param Database $database Parent database (provides the shared HTTP transport).
     * @param string   $name     Name of the target graph on the server.
     */
    public function __construct( public Database $database , public string $name ) {}

    /**
     * Wire field carrying the list of edge definitions on a graph.
     */
    public const string EDGE_DEFINITIONS_FIELD = 'edgeDefinitions' ;

    /**
     * Wire field carrying the graph wrapper in single-graph responses
     * (`POST /_api/gharial`, `GET /_api/gharial/{name}`,
     * `POST|PUT|DELETE .../vertex` / `.../edge`).
     */
    public const string GRAPH_FIELD = 'graph' ;

    /**
     * Wire field carrying the graph name.
     */
    public const string NAME_FIELD = 'name' ;

    /**
     * Wire field carrying the orphan-collections list of a graph
     * (vertex collections that belong to the graph but are not part of
     * any edge definition).
     */
    public const string ORPHAN_COLLECTIONS_FIELD = 'orphanCollections' ;

    /**
     * Query parameter that propagates a drop down to the underlying
     * vertex/edge collections (`/_api/gharial/{graph}/{...}/{name}?dropCollection=true`).
     */
    public const string DROP_COLLECTION_PARAM = 'dropCollection' ;

    /**
     * Query parameter that propagates a drop down to every vertex and
     * edge collection of the graph (`/_api/gharial/{name}?dropCollections=true`).
     */
    public const string DROP_COLLECTIONS_PARAM = 'dropCollections' ;

    /**
     * Sub-route for the edge-collection management endpoint
     * (`/_api/gharial/{graph}/edge`).
     */
    private const string EDGE_SUB_ROUTE = '/edge' ;

    /**
     * Sub-route for the vertex-collection management endpoint
     * (`/_api/gharial/{graph}/vertex`).
     */
    private const string VERTEX_SUB_ROUTE = '/vertex' ;

    /**
     * Wire field carrying the list of vertex collections (gharial-style
     * — graphs-level), on `GET /_api/gharial/{name}/vertex`.
     */
    private const string COLLECTIONS_FIELD = 'collections' ;

    /**
     * Adds an edge definition to this graph.
     *
     * Wraps `POST /_api/gharial/{name}/edge`. The server validates
     * that the edge collection is not already part of another graph
     * with a conflicting definition and rejects on
     * `1928` (`GRAPH_EDGE_COLLECTION_USED`).
     *
     * @param EdgeDefinition $definition The edge definition to register.
     *
     * @return array<string, mixed> Raw `graph` payload as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function addEdgeDefinition( EdgeDefinition $definition ) : array
    {
        return $this->extractGraph
        (
            $this->database->request
            (
                method : HttpMethod::POST ,
                path   : $this->path() . self::EDGE_SUB_ROUTE ,
                body   : $definition->toArray() ,
            )->body ,
        ) ;
    }

    /**
     * Adds a vertex collection to this graph (registers a new orphan
     * collection — orphan because it is not yet part of any edge
     * definition).
     *
     * Wraps `POST /_api/gharial/{name}/vertex`. The server creates the
     * collection if it does not exist yet.
     *
     * @param string $collection Name of the vertex collection to register.
     *
     * @return array<string, mixed> Raw `graph` payload as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function addVertexCollection( string $collection ) : array
    {
        return $this->extractGraph
        (
            $this->database->request
            (
                method : HttpMethod::POST ,
                path   : $this->path() . self::VERTEX_SUB_ROUTE ,
                body   : [ EdgeDefinition::COLLECTION => $collection ],
            )->body ,
        ) ;
    }

    /**
     * Creates this graph on the server with the given edge definitions.
     *
     * Wraps `POST /_api/gharial`. The graph name is taken from
     * {@see $name}. Recognised options include `orphanCollections`
     * (an array of vertex collection names not yet part of any edge
     * definition), `numberOfShards`, `replicationFactor`,
     * `writeConcern`, `waitForSync`, `isSmart`, `smartGraphAttribute`,
     * `isDisjoint`, `satellites` — the last four are Enterprise-only
     * (silently ignored on Community editions).
     *
     * @param array<int, EdgeDefinition> $edgeDefinitions Edge definitions to register on creation (may be empty for a vertex-only / orphan graph).
     * @param array<string, mixed>       $options         Extra creation options.
     *
     * @return array<string, mixed> Raw `graph` payload as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function create( array $edgeDefinitions = [] , array $options = [] ) : array
    {
        $body = array_merge
        (
            $options ,
            [
                self::NAME_FIELD             => $this->name ,
                self::EDGE_DEFINITIONS_FIELD => array_map
                (
                    static fn( EdgeDefinition $def ) : array => $def->toArray() ,
                    $edgeDefinitions ,
                ) ,
            ] ,
        ) ;

        return $this->extractGraph
        (
            $this->database->request
            (
                method : HttpMethod::POST ,
                path   : ArangoRoute::GHARIAL ,
                body   : $body ,
            )->body ,
        ) ;
    }

    /**
     * Drops this graph from the server.
     *
     * Wraps `DELETE /_api/gharial/{name}`. When `$dropCollections` is
     * true, every vertex and edge collection that belongs to this
     * graph (and is not shared with another graph) is also dropped —
     * use with care, data is lost. When false (default), the
     * collections are kept as orphans.
     *
     * @param bool $dropCollections Whether to drop the underlying vertex/edge collections.
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function drop( bool $dropCollections = false ) : void
    {
        $this->database->request
        (
            method : HttpMethod::DELETE ,
            path   : $this->path() ,
            query  : $dropCollections ? [ self::DROP_COLLECTIONS_PARAM => 'true' ] : [] ,
        ) ;
    }

    /**
     * Returns a {@see GraphEdgeCollection} handle on the given edge
     * collection inside this graph.
     *
     * No HTTP call is made — the handle is purely client-side.
     * Routes its CRUD operations through `/_api/gharial/{graph}/edge/{collection}/...`
     * so the server enforces the graph's edge-definition constraints
     * on `_from` / `_to`.
     *
     * @param string $name Name of the edge collection.
     *
     * @return GraphEdgeCollection
     */
    public function edgeCollection( string $name ) : GraphEdgeCollection
    {
        return new GraphEdgeCollection( $this , $name ) ;
    }

    /**
     * Returns the list of edge collection names currently registered
     * on this graph (the names — for the full definitions including
     * `from` / `to`, use {@see edgeDefinitions()}).
     *
     * Wraps `GET /_api/gharial/{name}/edge`.
     *
     * @return array<int, string>
     *
     * @throws ArangoException When the request fails.
     */
    public function edgeCollections() : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->path() . self::EDGE_SUB_ROUTE ,
        ) ;

        $body = is_array( $response->body ) ? $response->body : [] ;
        $list = $body[ self::COLLECTIONS_FIELD ] ?? null ;

        return is_array( $list ) ? array_values( array_filter( $list , 'is_string' ) ) : [] ;
    }

    /**
     * Returns the list of edge definitions currently registered on
     * this graph, as typed {@see EdgeDefinition} value objects.
     *
     * Reads from `GET /_api/gharial/{name}` (the same endpoint as
     * {@see get()}), so it costs a single round trip.
     *
     * @return array<int, EdgeDefinition>
     *
     * @throws ArangoException When the request fails.
     */
    public function edgeDefinitions() : array
    {
        $graph = $this->get() ;
        $raw   = $graph[ self::EDGE_DEFINITIONS_FIELD ] ?? null ;

        if ( !is_array( $raw ) )
        {
            return [] ;
        }

        $definitions = [] ;

        foreach ( $raw as $entry )
        {
            if ( is_array( $entry ) )
            {
                $definitions[] = EdgeDefinition::fromArray( $entry ) ;
            }
        }

        return $definitions ;
    }

    /**
     * Returns true when the graph exists on the server.
     *
     * Treats a 404 as a clean "missing" and rethrows everything else.
     *
     * @return bool
     *
     * @throws ArangoException When the request fails for a reason other than a 404.
     */
    public function exists() : bool
    {
        try
        {
            $this->database->request( method : HttpMethod::GET , path : $this->path() ) ;
            return true ;
        }
        catch ( HttpException $e )
        {
            if ( $e->getCode() === 404 )
            {
                return false ;
            }
            throw $e ;
        }
    }

    /**
     * Returns the raw server-side description of this graph
     * (`GET /_api/gharial/{name}`).
     *
     * Carries `_key` / `_id` / `_rev` / `name` / `edgeDefinitions` /
     * `orphanCollections`, and Enterprise-only `numberOfShards` /
     * `replicationFactor` / `writeConcern` / `isSmart` / … on cluster
     * deployments.
     *
     * @return array<string, mixed>
     *
     * @throws ArangoException When the request fails.
     */
    public function get() : array
    {
        return $this->extractGraph
        (
            $this->database->request
            (
                method : HttpMethod::GET ,
                path   : $this->path() ,
            )->body ,
        ) ;
    }

    /**
     * Returns the graph name this instance is bound to.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name ;
    }

    /**
     * Returns the list of orphan vertex collections currently
     * registered on this graph.
     *
     * @return array<int, string>
     *
     * @throws ArangoException When the request fails.
     */
    public function orphanCollections() : array
    {
        $graph = $this->get() ;
        $raw   = $graph[ self::ORPHAN_COLLECTIONS_FIELD ] ?? null ;

        if ( !is_array( $raw ) )
        {
            return [] ;
        }

        return array_values( array_filter( $raw , 'is_string' ) ) ;
    }

    /**
     * Removes an edge definition from this graph.
     *
     * Wraps `DELETE /_api/gharial/{name}/edge/{collection}`. When
     * `$dropCollection` is true, the edge collection itself is also
     * dropped from the database (data is lost).
     *
     * @param string $collection     Name of the edge collection whose definition is being removed.
     * @param bool   $dropCollection Also drop the underlying edge collection.
     *
     * @return array<string, mixed> Raw `graph` payload as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function removeEdgeDefinition( string $collection , bool $dropCollection = false ) : array
    {
        return $this->extractGraph
        (
            $this->database->request
            (
                method : HttpMethod::DELETE ,
                path   : $this->path() . self::EDGE_SUB_ROUTE . '/' . rawurlencode( $collection ) ,
                query  : $dropCollection ? [ self::DROP_COLLECTION_PARAM => 'true' ] : [] ,
            )->body ,
        ) ;
    }

    /**
     * Removes a vertex collection from this graph (must be an orphan
     * collection — vertex collections referenced by an edge
     * definition cannot be removed until the definition itself is
     * removed first).
     *
     * Wraps `DELETE /_api/gharial/{name}/vertex/{collection}`. When
     * `$dropCollection` is true, the underlying vertex collection is
     * also dropped from the database.
     *
     * @param string $collection     Name of the vertex collection.
     * @param bool   $dropCollection Also drop the underlying vertex collection.
     *
     * @return array<string, mixed> Raw `graph` payload as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function removeVertexCollection( string $collection , bool $dropCollection = false ) : array
    {
        return $this->extractGraph
        (
            $this->database->request
            (
                method : HttpMethod::DELETE ,
                path   : $this->path() . self::VERTEX_SUB_ROUTE . '/' . rawurlencode( $collection ) ,
                query  : $dropCollection ? [ self::DROP_COLLECTION_PARAM => 'true' ] : [] ,
            )->body ,
        ) ;
    }

    /**
     * Replaces an existing edge definition with a new one for the
     * same edge collection.
     *
     * Wraps `PUT /_api/gharial/{name}/edge/{collection}`. Useful to
     * widen or narrow the allowed `from` / `to` vertex collections
     * without recreating the graph.
     *
     * @param EdgeDefinition $definition New definition. The `collection` field selects the target.
     *
     * @return array<string, mixed> Raw `graph` payload as returned by the server.
     *
     * @throws ArangoException When the request fails.
     */
    public function replaceEdgeDefinition( EdgeDefinition $definition ) : array
    {
        return $this->extractGraph
        (
            $this->database->request
            (
                method : HttpMethod::PUT ,
                path   : $this->path() . self::EDGE_SUB_ROUTE . '/' . rawurlencode( $definition->collection ) ,
                body   : $definition->toArray() ,
            )->body ,
        ) ;
    }

    /**
     * Returns a {@see GraphVertexCollection} handle on the given vertex
     * collection inside this graph.
     *
     * No HTTP call is made — the handle is purely client-side.
     * Routes its CRUD operations through `/_api/gharial/{graph}/vertex/{collection}/...`
     * so the server enforces the graph's referential constraints
     * (e.g. preventing edge collections from referencing a vertex
     * that does not exist).
     *
     * @param string $name Name of the vertex collection.
     *
     * @return GraphVertexCollection
     */
    public function vertexCollection( string $name ) : GraphVertexCollection
    {
        return new GraphVertexCollection( $this , $name ) ;
    }

    /**
     * Returns the list of vertex collection names currently
     * registered on this graph (both orphan collections and the ones
     * referenced by an edge definition).
     *
     * Wraps `GET /_api/gharial/{name}/vertex`.
     *
     * @return array<int, string>
     *
     * @throws ArangoException When the request fails.
     */
    public function vertexCollections() : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->path() . self::VERTEX_SUB_ROUTE ,
        ) ;

        $body = is_array( $response->body ) ? $response->body : [] ;
        $list = $body[ self::COLLECTIONS_FIELD ] ?? null ;

        return is_array( $list ) ? array_values( array_filter( $list , 'is_string' ) ) : [] ;
    }

    /**
     * Builds the `/_api/gharial/{name}` path with the graph name URL-encoded.
     *
     * @return string
     */
    private function path() : string
    {
        return ArangoRoute::GHARIAL . '/' . rawurlencode( $this->name ) ;
    }

    /**
     * Extracts the `graph` payload from a single-graph response body,
     * falling back to the body itself when the wrapper is absent
     * (defensive — the server always emits the wrapper on success).
     *
     * @param mixed $body Decoded response body.
     *
     * @return array<string, mixed>
     */
    private function extractGraph( mixed $body ) : array
    {
        return is_array( $body ) ? unwrapField( $body , self::GRAPH_FIELD , $body ) : [] ;
    }
}
