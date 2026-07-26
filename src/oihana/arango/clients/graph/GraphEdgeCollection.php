<?php

namespace oihana\arango\clients\graph ;

use oihana\arango\clients\document\Document ;
use oihana\arango\clients\document\Edge ;
use oihana\arango\clients\exceptions\ArangoException ;

/**
 * Edge-CRUD handle on an edge collection that belongs to a named
 * graph.
 *
 * The whole CRUD surface lives in {@see GraphCollection}; this class supplies
 * the three things specific to edges — the `/edge` route segment, the `edge`
 * response wrapper, and the {@see Edge} value object each response is turned
 * into — plus the narrowed return types that keep `Edge` in the public
 * signatures.
 *
 * Routing through the gharial endpoint family
 * (`/_api/gharial/{graph}/edge/{collection}[/{key}]`) rather than the generic
 * document API lets the server enforce the graph's edge-definition constraints
 * on `_from` / `_to` — inserting an edge with a `_from` pointing outside the
 * allowed vertex collections fails up-front rather than silently corrupting the
 * graph topology.
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
readonly class GraphEdgeCollection extends GraphCollection
{
    /**
     * Sub-route segment used to scope a request to the edge surface
     * of the gharial endpoints (`/_api/gharial/{graph}/edge/...`).
     */
    protected const string SUB_ROUTE = '/edge' ;

    /**
     * Wire field carrying the edge payload inside the gharial
     * response wrapper.
     */
    protected const string WRAPPER_FIELD = 'edge' ;

    /**
     * Fetches a single edge by key.
     *
     * @param string $key The edge key (`_key`).
     *
     * @return Edge
     *
     * @throws ArangoException When the edge is missing or the request fails.
     */
    public function document( string $key ) : Edge
    {
        /** @var Edge */
        return parent::document( $key ) ;
    }

    /**
     * Inserts a new edge into the collection.
     *
     * @param array<string, mixed> $data    Edge payload (`_from` and `_to` required).
     * @param array<string, mixed> $options Server-side options (`returnNew`, `waitForSync`).
     *
     * @return Edge
     *
     * @throws ArangoException When the request fails.
     */
    public function insert( array $data , array $options = [] ) : Edge
    {
        /** @var Edge */
        return parent::insert( $data , $options ) ;
    }

    /**
     * Removes an edge from the collection.
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
        /** @var Edge */
        return parent::remove( $key , $options ) ;
    }

    /**
     * Replaces an existing edge with the given payload (PUT semantics — fields
     * absent from `$data` are dropped, so `_from` / `_to` must be resent).
     *
     * @param string               $key     Edge key.
     * @param array<string, mixed> $data    Replacement payload.
     * @param array<string, mixed> $options Server-side options (`returnNew`, `returnOld`, `waitForSync`, `keepNull`).
     *
     * @return Edge
     *
     * @throws ArangoException When the request fails.
     */
    public function replace( string $key , array $data , array $options = [] ) : Edge
    {
        /** @var Edge */
        return parent::replace( $key , $data , $options ) ;
    }

    /**
     * Partially updates an existing edge with the given payload (PATCH
     * semantics — only the supplied fields are touched).
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
        /** @var Edge */
        return parent::update( $key , $partial , $options ) ;
    }

    /**
     * @inheritDoc
     */
    protected function createDocument( array $data = [] ) : Document
    {
        return new Edge( $data ) ;
    }
}
