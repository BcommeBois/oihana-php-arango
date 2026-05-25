<?php

namespace oihana\arango\clients\graph ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\document\Edge ;
use oihana\arango\clients\document\enums\DocumentField ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;

use function oihana\arango\clients\helpers\stringifyOptions ;
use function oihana\arango\clients\helpers\unwrapField ;

/**
 * Edge-CRUD handle on an edge collection that belongs to a named
 * graph.
 *
 * Routes every CRUD call through the gharial endpoint family
 * (`/_api/gharial/{graph}/edge/{collection}[/{key}]`) instead of the
 * generic document API (`/_api/document/...`). Going through gharial
 * lets the server enforce the graph's edge-definition constraints
 * on `_from` / `_to` — inserting an edge with a `_from` pointing
 * outside the allowed vertex collections fails up-front rather than
 * silently corrupting the graph topology.
 *
 * Instances are obtained through {@see Graph::edgeCollection()}.
 *
 * Returns typed {@see Edge} value objects (sub-class of `Document`
 * exposing `getFrom()` / `getTo()`), exactly like the non-graph
 * {@see \oihana\arango\clients\collection\EdgeCollection}.
 * The gharial response wrapper (`{ edge: {...} }`) is unwrapped
 * internally so callers never see it.
 *
 * Example:
 * ```php
 * $graph   = $db->graph( 'workplaces' ) ;
 * $employs = $graph->edgeCollection( 'employs' ) ;
 *
 * $edge = $employs->insert
 * (
 *     [
 *         '_from' => 'companies/acme' ,
 *         '_to'   => 'people/alice'   ,
 *         'since' => '2024-01-01'     ,
 *     ] ,
 *     [ 'returnNew' => true ] ,
 * ) ;
 *
 * $employs->update( $edge->getKey() , [ 'since' => '2024-06-01' ] ) ;
 * $employs->remove( $edge->getKey() ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/graphs/named-graphs/#edges
 *
 * @package oihana\arango\clients\graph
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class GraphEdgeCollection
{
    /**
     * @param Graph  $graph Parent graph.
     * @param string $name  Name of the edge collection on the server.
     */
    public function __construct( public Graph $graph , public string $name ) {}

    /**
     * Sub-route segment used to scope a request to the edge surface
     * of the gharial endpoints (`/_api/gharial/{graph}/edge/...`).
     */
    private const string SUB_ROUTE = '/edge' ;

    /**
     * Wire field carrying the document payload inside the gharial
     * response wrapper.
     */
    private const string WRAPPER_FIELD = 'edge' ;

    /**
     * Fetches a single edge by key.
     *
     * Wraps `GET /_api/gharial/{graph}/edge/{collection}/{key}`. The
     * server returns the document inside a `{edge: {...}}` envelope,
     * which is unwrapped here.
     *
     * @param string $key The edge key (`_key`).
     *
     * @return Edge
     *
     * @throws ArangoException When the edge is missing or the request fails.
     */
    public function document( string $key ) : Edge
    {
        $response = $this->graph->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->documentPath( $key ) ,
        ) ;

        return new Edge( $this->unwrap( $response->body ) ) ;
    }

    /**
     * Returns true when an edge with the given key exists in this
     * collection inside the graph.
     *
     * Uses `GET /_api/gharial/{graph}/edge/{collection}/{key}` and
     * swallows the 404 branch. Any other failure rethrows as an
     * {@see ArangoException}.
     *
     * @param string $key The edge key.
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
     * Returns the edge collection name this instance is bound to.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name ;
    }

    /**
     * Inserts a new edge into the collection through the gharial
     * endpoint (`POST /_api/gharial/{graph}/edge/{collection}`).
     *
     * The payload MUST carry valid `_from` / `_to` values pointing at
     * existing documents in one of the vertex collections the graph's
     * edge definition allows — the server rejects mismatches with a
     * 4xx response.
     *
     * @param array<string, mixed> $data    Edge payload (`_from` / `_to` mandatory).
     * @param array<string, mixed> $options Server-side options (`returnNew`, `waitForSync`).
     *
     * @return Edge
     *
     * @throws ArangoException When the request fails.
     */
    public function insert( array $data , array $options = [] ) : Edge
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
     * Removes an edge from the collection through the gharial endpoint.
     *
     * Wraps `DELETE /_api/gharial/{graph}/edge/{collection}/{key}`.
     * Pass `returnOld: true` in `$options` to receive the deleted
     * payload in the resulting {@see Edge}.
     *
     * @param string               $key     Edge key.
     * @param array<string, mixed> $options Server-side options (`returnOld`, `waitForSync`, `rev`).
     *
     * @return Edge
     *
     * @throws ArangoException When the request fails.
     */
    public function remove( string $key , array $options = [] ) : Edge
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
     * Replaces an existing edge with the given payload (PUT semantics
     * — fields absent from `$data` are dropped).
     *
     * Wraps `PUT /_api/gharial/{graph}/edge/{collection}/{key}`. The
     * payload MUST carry valid `_from` / `_to` values; PUT semantics
     * mean missing fields are dropped, including the endpoints — the
     * server rejects on missing endpoints.
     *
     * @param string               $key     Edge key.
     * @param array<string, mixed> $data    Replacement payload (must include `_from` / `_to`).
     * @param array<string, mixed> $options Server-side options (`returnNew`, `returnOld`, `waitForSync`, `keepNull`).
     *
     * @return Edge
     *
     * @throws ArangoException When the request fails.
     */
    public function replace( string $key , array $data , array $options = [] ) : Edge
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
     * Partially updates an existing edge with the given payload
     * (PATCH semantics — only the supplied fields are touched).
     *
     * Wraps `PATCH /_api/gharial/{graph}/edge/{collection}/{key}`.
     * Useful to change metadata fields without re-stating
     * `_from` / `_to`.
     *
     * @param string               $key     Edge key.
     * @param array<string, mixed> $partial Partial payload.
     * @param array<string, mixed> $options Server-side options (`returnNew`, `returnOld`, `keepNull`, `waitForSync`).
     *
     * @return Edge
     *
     * @throws ArangoException When the request fails.
     */
    public function update( string $key , array $partial , array $options = [] ) : Edge
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
     * Builds the `/_api/gharial/{graph}/edge/{collection}` path with
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
     * Builds the `/_api/gharial/{graph}/edge/{collection}/{key}` path
     * with every segment URL-encoded.
     *
     * @param string $key Edge key.
     *
     * @return string
     */
    private function documentPath( string $key ) : string
    {
        return $this->collectionPath() . '/' . rawurlencode( $key ) ;
    }

    /**
     * Extracts the `edge` wrapper from a gharial response body,
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
     * Wraps a write-operation response body into an {@see Edge}.
     *
     * The server wraps the meta document under an `edge` key on
     * every gharial endpoint. When the caller requested `returnNew` /
     * `returnOld`, the payload is also present under `new` / `old`
     * at the top level — it is merged on top of the unwrapped meta,
     * with the meta attributes (`_key` / `_id` / `_rev` / `_from` /
     * `_to`) taking precedence on key collisions.
     *
     * @param mixed  $body         Decoded response body.
     * @param string $payloadField Payload field name (`new` for insert/replace/update, `old` for remove).
     *
     * @return Edge
     */
    private function wrapWritten( mixed $body , string $payloadField ) : Edge
    {
        if ( !is_array( $body ) )
        {
            return new Edge() ;
        }

        $meta = unwrapField( $body , self::WRAPPER_FIELD , $body ) ;

        if ( isset( $body[ $payloadField ] ) && is_array( $body[ $payloadField ] ) )
        {
            $meta = array_merge( $body[ $payloadField ] , $meta ) ;
        }

        return new Edge( $meta ) ;
    }
}
