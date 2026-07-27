<?php

namespace oihana\arango\clients\graph ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\document\Document ;
use oihana\arango\clients\document\enums\DocumentField ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;

use function oihana\arango\clients\helpers\probeExists ;
use function oihana\arango\clients\helpers\stringifyOptions ;
use function oihana\arango\clients\helpers\unwrapField ;

/**
 * CRUD handle on a collection that belongs to a named graph, shared by the
 * vertex and the edge surfaces.
 *
 * Routes every call through the gharial endpoint family
 * (`/_api/gharial/{graph}/{surface}/{collection}[/{key}]`) instead of the
 * generic document API (`/_api/document/...`). Going through gharial lets the
 * server enforce the graph's edge-definition constraints — inserting an edge
 * that points at a missing vertex, for instance, is rejected up-front.
 *
 * The two surfaces differ by three things only, which is exactly what a subclass
 * supplies: the route segment ({@see GraphCollection::SUB_ROUTE}), the field the
 * server wraps the payload in ({@see GraphCollection::WRAPPER_FIELD}), and the
 * value object each response is turned into
 * ({@see GraphCollection::createDocument()}). Everything else — the requests,
 * the unwrapping of the gharial envelope, the `returnNew` / `returnOld` merge,
 * the 404 branch of the existence probe — is identical and lives here.
 *
 * Instances are obtained through {@see Graph::vertexCollection()} and
 * {@see Graph::edgeCollection()}.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/graphs/named-graphs/
 *
 * @package oihana\arango\clients\graph
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.6.0
 */
abstract readonly class GraphCollection
{
    /**
     * @param Graph  $graph Parent graph.
     * @param string $name  Name of the collection on the server.
     */
    public function __construct( public Graph $graph , public string $name ) {}

    /**
     * Sub-route segment scoping a request to one surface of the gharial
     * endpoints (`/vertex` or `/edge`), supplied by the subclass.
     */
    protected const string SUB_ROUTE = '' ;

    /**
     * Wire field carrying the document payload inside the gharial response
     * wrapper (`vertex` or `edge`), supplied by the subclass.
     */
    protected const string WRAPPER_FIELD = '' ;

    /**
     * Fetches a single document by key.
     *
     * Wraps `GET /_api/gharial/{graph}/{surface}/{collection}/{key}`. The server
     * returns the document inside a `{<surface>: {...}}` envelope, which is
     * unwrapped here.
     *
     * @param string $key The document key (`_key`).
     *
     * @return Document
     *
     * @throws ArangoException When the document is missing or the request fails.
     */
    public function document( string $key ) : Document
    {
        $response = $this->graph->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->documentPath( $key ) ,
        ) ;

        return $this->createDocument( $this->unwrap( $response->body ) ) ;
    }

    /**
     * Returns true when a document with the given key exists in this collection
     * inside the graph.
     *
     * Uses `GET /_api/gharial/{graph}/{surface}/{collection}/{key}` and swallows the 404 branch.
     * Any other failure rethrows as an {@see ArangoException}.
     *
     * **The `GET` is not an oversight, and must not be "optimized" into a
     * `HEAD`.** The verb costs a full document transfer for an answer one bit
     * wide, so `HEAD` would be the natural choice — and it is what the non-graph
     * {@see \oihana\arango\clients\collection\Collection::documentExists()} uses.
     * The gharial endpoints do not support it: a `HEAD` on this route answers
     * **HTTP 500**, on an existing key as well as on a missing one, for both the
     * vertex and the edge surface. Measured against arangod, which answers
     * `200` / `404` to the very same `HEAD` on the generic `/_api/document`
     * route — so the limitation is the server's, not the client's. Switching
     * would break the method outright, and no unit test would catch it: they all
     * stub the transport and honour whatever verb they are handed.
     *
     * A caller on a hot path can bypass gharial and probe the underlying
     * collection directly (`$db->collection( $name )->documentExists( $key )`),
     * which does send a `HEAD` — the graph constraints gharial enforces are a
     * write-time concern and buy nothing on a read.
     *
     * @param string $key The document key.
     *
     * @return bool
     *
     * @throws ArangoException When the request fails for a reason other than a 404.
     */
    public function documentExists( string $key ) : bool
    {
        return probeExists( fn() => $this->graph->database->request( HttpMethod::GET , $this->documentPath( $key ) ) ) ;
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
     * Returns the collection name this instance is bound to.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name ;
    }

    /**
     * Inserts a new document into the collection through the gharial endpoint
     * (`POST /_api/gharial/{graph}/{surface}/{collection}`).
     *
     * @param array<string, mixed> $data    Payload (`_key` optional; server-assigned when absent).
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
     * Removes a document from the collection through the gharial endpoint.
     *
     * Wraps `DELETE /_api/gharial/{graph}/{surface}/{collection}/{key}`. Pass
     * `returnOld: true` in `$options` to receive the deleted payload.
     *
     * @param string               $key     Document key.
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
     * Replaces an existing document with the given payload (PUT semantics —
     * fields absent from `$data` are dropped).
     *
     * Wraps `PUT /_api/gharial/{graph}/{surface}/{collection}/{key}`.
     *
     * @param string               $key     Document key.
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
     * Partially updates an existing document with the given payload (PATCH
     * semantics — only the supplied fields are touched).
     *
     * Wraps `PATCH /_api/gharial/{graph}/{surface}/{collection}/{key}`.
     *
     * @param string               $key     Document key.
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
     * Builds the value object every response of this surface is turned into: a
     * {@see Document} for a vertex collection, an
     * {@see \oihana\arango\clients\document\Edge} for an edge one.
     *
     * @param array<string, mixed> $data Decoded document attributes.
     *
     * @return Document
     */
    abstract protected function createDocument( array $data = [] ) : Document ;

    /**
     * Builds the `/_api/gharial/{graph}/{surface}/{collection}` path with both
     * segments URL-encoded.
     *
     * @return string
     */
    private function collectionPath() : string
    {
        return ArangoRoute::GHARIAL
             . '/' . rawurlencode( $this->graph->name )
             . static::SUB_ROUTE
             . '/' . rawurlencode( $this->name ) ;
    }

    /**
     * Builds the `/_api/gharial/{graph}/{surface}/{collection}/{key}` path with
     * every segment URL-encoded.
     *
     * @param string $key Document key.
     *
     * @return string
     */
    private function documentPath( string $key ) : string
    {
        return $this->collectionPath() . '/' . rawurlencode( $key ) ;
    }

    /**
     * Extracts the surface wrapper from a gharial response body, falling back to
     * the body itself when the wrapper is absent (defensive — the server always
     * emits it on success).
     *
     * @param mixed $body Decoded response body.
     *
     * @return array<string, mixed>
     */
    private function unwrap( mixed $body ) : array
    {
        return is_array( $body ) ? unwrapField( $body , static::WRAPPER_FIELD , $body ) : [] ;
    }

    /**
     * Wraps a write-operation response body into a value object.
     *
     * The server wraps the meta document under the surface key on every gharial
     * endpoint. When the caller requested `returnNew` / `returnOld`, the payload
     * is also present under `new` / `old` at the top level — it is merged on top
     * of the unwrapped meta, with the meta attributes (`_key` / `_id` / `_rev`)
     * taking precedence on key collisions.
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
            return $this->createDocument() ;
        }

        $meta = unwrapField( $body , static::WRAPPER_FIELD , $body ) ;

        if ( isset( $body[ $payloadField ] ) && is_array( $body[ $payloadField ] ) )
        {
            $meta = array_merge( $body[ $payloadField ] , $meta ) ;
        }

        return $this->createDocument( $meta ) ;
    }
}
