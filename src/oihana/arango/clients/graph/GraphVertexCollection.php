<?php

namespace oihana\arango\clients\graph ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\document\Document ;
use oihana\arango\clients\document\enums\DocumentField ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;

use function oihana\arango\clients\helpers\stringifyOptions ;
use function oihana\arango\clients\helpers\unwrapField ;

/**
 * Vertex-CRUD handle on a vertex collection that belongs to a named
 * graph.
 *
 * Routes every CRUD call through the gharial endpoint family
 * (`/_api/gharial/{graph}/vertex/{collection}[/{key}]`) instead of the
 * generic document API (`/_api/document/...`). Going through gharial
 * lets the server enforce the graph's edge-definition constraints —
 * inserting an edge later that points at a missing vertex, for
 * instance, is rejected up-front.
 *
 * Instances are obtained through {@see Graph::vertexCollection()}.
 *
 * Returns plain {@see Document} value objects, exactly like the
 * non-graph {@see \oihana\arango\clients\collection\Collection}. The
 * gharial response wrapper (`{ vertex: {...} }`) is unwrapped
 * internally so callers never see it.
 *
 * Example:
 * ```php
 * $graph = $db->graph( 'workplaces' ) ;
 * $people = $graph->vertexCollection( 'people' ) ;
 *
 * $alice = $people->insert( [ '_key' => 'alice' , 'name' => 'Alice' ] , [ 'returnNew' => true ] ) ;
 *
 * if ( $people->documentExists( 'alice' ) )
 * {
 *     $people->update( 'alice' , [ 'role' => 'admin' ] ) ;
 * }
 *
 * $people->remove( 'alice' ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/graphs/named-graphs/#vertices
 *
 * @package oihana\arango\clients\graph
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class GraphVertexCollection
{
    /**
     * @param Graph  $graph Parent graph.
     * @param string $name  Name of the vertex collection on the server.
     */
    public function __construct( public Graph $graph , public string $name ) {}

    /**
     * Sub-route segment used to scope a request to the vertex
     * surface of the gharial endpoints
     * (`/_api/gharial/{graph}/vertex/...`).
     */
    private const string SUB_ROUTE = '/vertex' ;

    /**
     * Wire field carrying the document payload inside the gharial
     * response wrapper.
     */
    private const string WRAPPER_FIELD = 'vertex' ;

    /**
     * Fetches a single vertex by key.
     *
     * Wraps `GET /_api/gharial/{graph}/vertex/{collection}/{key}`. The
     * server returns the document inside a `{vertex: {...}}` envelope,
     * which is unwrapped here.
     *
     * @param string $key The vertex key (`_key`).
     *
     * @return Document
     *
     * @throws ArangoException When the vertex is missing or the request fails.
     */
    public function document( string $key ) : Document
    {
        $response = $this->graph->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->documentPath( $key ) ,
        ) ;

        return new Document( $this->unwrap( $response->body ) ) ;
    }

    /**
     * Returns true when a vertex with the given key exists in this
     * collection inside the graph.
     *
     * Uses `GET /_api/gharial/{graph}/vertex/{collection}/{key}` and
     * swallows the 404 branch. Any other failure rethrows as an
     * {@see ArangoException}.
     *
     * @param string $key The vertex key.
     *
     * @return bool
     *
     * @throws ArangoException When the request fails for a reason other than a 404.
     */
    public function documentExists( string $key ) : bool
    {
        try
        {
            $this->graph->database->request
            (
                method : HttpMethod::GET ,
                path   : $this->documentPath( $key ) ,
            ) ;
            return true ;
        }
        catch ( ArangoException $e )
        {
            if ( $e->getCode() === 404 )
            {
                return false ;
            }
            throw $e ;
        }
    }

    /**
     * Returns the parent graph this collection is bound to.
     *
     * @return Graph
     */
    public function getGraph() : Graph
    {
        return $this->graph ;
    }

    /**
     * Returns the vertex collection name this instance is bound to.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name ;
    }

    /**
     * Inserts a new vertex into the collection through the gharial
     * endpoint (`POST /_api/gharial/{graph}/vertex/{collection}`).
     *
     * @param array<string, mixed> $data    Vertex payload (`_key` optional; server-assigned when absent).
     * @param array<string, mixed> $options Server-side options (`returnNew`, `waitForSync`).
     *
     * @return Document
     *
     * @throws ArangoException When the request fails.
     */
    public function insert( array $data , array $options = [] ) : Document
    {
        $response = $this->graph->database->request
        (
            method : HttpMethod::POST ,
            path   : $this->collectionPath() ,
            body   : $data ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWritten( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Removes a vertex from the collection through the gharial
     * endpoint.
     *
     * Wraps `DELETE /_api/gharial/{graph}/vertex/{collection}/{key}`.
     * Pass `returnOld: true` in `$options` to receive the deleted
     * payload in the resulting {@see Document}.
     *
     * @param string               $key     Vertex key.
     * @param array<string, mixed> $options Server-side options (`returnOld`, `waitForSync`, `rev`).
     *
     * @return Document
     *
     * @throws ArangoException When the request fails.
     */
    public function remove( string $key , array $options = [] ) : Document
    {
        $response = $this->graph->database->request
        (
            method : HttpMethod::DELETE ,
            path   : $this->documentPath( $key ) ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWritten( $response->body , DocumentField::OLD ) ;
    }

    /**
     * Replaces an existing vertex with the given payload (PUT
     * semantics — fields absent from `$data` are dropped).
     *
     * Wraps `PUT /_api/gharial/{graph}/vertex/{collection}/{key}`.
     *
     * @param string               $key     Vertex key.
     * @param array<string, mixed> $data    Replacement payload.
     * @param array<string, mixed> $options Server-side options (`returnNew`, `returnOld`, `waitForSync`, `keepNull`).
     *
     * @return Document
     *
     * @throws ArangoException When the request fails.
     */
    public function replace( string $key , array $data , array $options = [] ) : Document
    {
        $response = $this->graph->database->request
        (
            method : HttpMethod::PUT ,
            path   : $this->documentPath( $key ) ,
            body   : $data ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWritten( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Partially updates an existing vertex with the given payload
     * (PATCH semantics — only the supplied fields are touched).
     *
     * Wraps `PATCH /_api/gharial/{graph}/vertex/{collection}/{key}`.
     *
     * @param string               $key     Vertex key.
     * @param array<string, mixed> $partial Partial payload.
     * @param array<string, mixed> $options Server-side options (`returnNew`, `returnOld`, `keepNull`, `waitForSync`).
     *
     * @return Document
     *
     * @throws ArangoException When the request fails.
     */
    public function update( string $key , array $partial , array $options = [] ) : Document
    {
        $response = $this->graph->database->request
        (
            method : HttpMethod::PATCH ,
            path   : $this->documentPath( $key ) ,
            body   : $partial ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWritten( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Builds the `/_api/gharial/{graph}/vertex/{collection}` path with
     * both segments URL-encoded.
     *
     * @return string
     */
    private function collectionPath() : string
    {
        return ArangoRoute::GHARIAL
             . '/' . rawurlencode( $this->graph->name )
             . self::SUB_ROUTE
             . '/' . rawurlencode( $this->name ) ;
    }

    /**
     * Builds the `/_api/gharial/{graph}/vertex/{collection}/{key}` path
     * with every segment URL-encoded.
     *
     * @param string $key Vertex key.
     *
     * @return string
     */
    private function documentPath( string $key ) : string
    {
        return $this->collectionPath() . '/' . rawurlencode( $key ) ;
    }

    /**
     * Extracts the `vertex` wrapper from a gharial response body,
     * falling back to the body itself when the wrapper is absent
     * (defensive — the server always emits it on success).
     *
     * @param mixed $body Decoded response body.
     *
     * @return array<string, mixed>
     */
    private function unwrap( mixed $body ) : array
    {
        return is_array( $body ) ? unwrapField( $body , self::WRAPPER_FIELD , $body ) : [] ;
    }

    /**
     * Wraps a write-operation response body into a {@see Document}.
     *
     * The server wraps the meta document under a `vertex` key on
     * every gharial endpoint. When the caller requested `returnNew` /
     * `returnOld`, the payload is also present under `new` / `old`
     * at the top level — it is merged on top of the unwrapped meta,
     * with the meta attributes (`_key` / `_id` / `_rev`) taking
     * precedence on key collisions.
     *
     * @param mixed  $body         Decoded response body.
     * @param string $payloadField Payload field name (`new` for insert/replace/update, `old` for remove).
     *
     * @return Document
     */
    private function wrapWritten( mixed $body , string $payloadField ) : Document
    {
        if ( !is_array( $body ) )
        {
            return new Document() ;
        }

        $meta = unwrapField( $body , self::WRAPPER_FIELD , $body ) ;

        if ( isset( $body[ $payloadField ] ) && is_array( $body[ $payloadField ] ) )
        {
            $meta = array_merge( $body[ $payloadField ] , $meta ) ;
        }

        return new Document( $meta ) ;
    }
}
