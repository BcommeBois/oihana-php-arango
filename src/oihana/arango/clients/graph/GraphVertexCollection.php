<?php

namespace oihana\arango\clients\graph ;

use oihana\arango\clients\document\Document ;

/**
 * Vertex-CRUD handle on a vertex collection that belongs to a named graph.
 *
 * The whole CRUD surface lives in {@see GraphCollection}; this class supplies
 * the three things specific to vertices — the `/vertex` route segment, the
 * `vertex` response wrapper, and the {@see Document} value object each response
 * is turned into.
 *
 * Instances are obtained through {@see Graph::vertexCollection()}.
 *
 * Returns plain {@see Document} value objects, exactly like the non-graph
 * {@see \oihana\arango\clients\collection\Collection}. The gharial response
 * wrapper (`{ vertex: {...} }`) is unwrapped internally so callers never see it.
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
readonly class GraphVertexCollection extends GraphCollection
{
    /**
     * Sub-route segment used to scope a request to the vertex surface of the
     * gharial endpoints (`/_api/gharial/{graph}/vertex/...`).
     */
    protected const string SUB_ROUTE = '/vertex' ;

    /**
     * Wire field carrying the document payload inside the gharial response
     * wrapper.
     */
    protected const string WRAPPER_FIELD = 'vertex' ;

    /**
     * @inheritDoc
     */
    protected function createDocument( array $data = [] ) : Document
    {
        return new Document( $data ) ;
    }
}
