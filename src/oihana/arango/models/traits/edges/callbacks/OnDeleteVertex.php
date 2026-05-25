<?php

namespace oihana\arango\models\traits\edges\callbacks;

use Throwable;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Purge;
use oihana\signals\notices\Payload;

use org\schema\constants\Schema;

use function oihana\arango\models\helpers\extractFromIds;
use function oihana\arango\models\helpers\extractToIDs;
use function oihana\core\normalize;

/**
 * Trait OnDeleteVertex
 *
 * This trait provides utilities to delete vertex when a document is removed
 * and automatically purge related vertex documents when a vertex involved in an edge is deleted.
 *
 * It is meant to be used within edge models with ArangoDB.
 *
 * The purge behavior is controlled via the `$purge` property, which accepts
 * values from the `Purge` enum:
 *
 * - `Purge::OUTBOUND` → Delete the "to" vertices when a "from" vertex is removed.
 * - `Purge::INBOUND`  → Delete the "from" vertices when a "to" vertex is removed.
 * - `Purge::BOTH`     → Delete both sides when the corresponding vertex is removed.
 *
 * Example:
 * ```php
 * $edge->purge = Purge::BOTH;
 * $payload = new Payload(data: $vertexToDelete, target: $edge->from);
 * $edge->onDeleteVertex($payload);
 * ```
 *
 * @author Marc
 * @version 1.0.0
 * @package oihana\arango\models\traits\edges\callbacks
 */
trait OnDeleteVertex
{
    /**
     * Constant for the method name to hook deletion events.
     */
    public const string ON_DELETE_VERTEX = 'onDeleteVertex' ;

    /**
     * Invoked when a vertex document is deleted.
     *
     * This method :
     * - Deletes all edges of the specific document resource ;
     * - Determinates which related vertex documents to purge based on the `$purge` property ;
     *
     * @param Payload $payload The payload containing:
     *  - `data`  : The deleted vertex or array of vertices.
     *  - `target`: The vertex model (`from` or `to`) from which deletion originates.
     *
     * @return void
     *
     * @throws Throwable
     *
     * Example:
     * ```php
     * $payload = new Payload(data: $deletedVertex, target: $edge->from);
     * $edge->onDeleteVertex($payload);
     * ```
     */
    public function onDeleteVertex( Payload $payload ):void
    {
        $data    = $payload->data   ?? null ;
        $target  = $payload->target ?? null ;

        if( isset( $data ) && isset( $target ) )
        {
            $edges = $this->deleteEdges
            (
                vertex : normalize
                (
                    is_array( $data )
                        ? array_map( fn( $doc ) => $doc?->_key ?? null , $data )
                        : $data->_key ?? null
                ),
                init : [ AQL::CONTEXT => $target ]
            ) ;

            $this->purgeVertices( $edges , $target ) ;
        }
    }

    /**
     * Purge related vertex documents based on the defined purge direction.
     *
     * This method is automatically called by `onDeleteVertex()` after an edge's vertex has been deleted.
     *
     * ### Usage
     * For example, suppose you have a `WebAPI` document connected to several `Permission` documents via edges.
     * If you delete the `WebAPI` document and the purge mode is set to `Purge::OUTBOUND` or `Purge::BOTH`,
     * all connected `Permission` vertices in their collection will be automatically removed.
     *
     * Similarly, if a `Permission` vertex is deleted and the purge mode is `Purge::INBOUND` or `Purge::BOTH`,
     * the related `WebAPI` vertices will be deleted according to the purge direction.
     *
     * ### Graph illustration of purge directions
     *
     * **OUTBOUND (delete TO when FROM is deleted):**
     * [FROM: WebAPI] ---> [TO: Permission]
     * DELETE WebAPI -> automatically DELETE Permission(s)
     *
     * **INBOUND (delete FROM when TO is deleted):**
     * [FROM: WebAPI] ---> [TO: Permission]
     * DELETE Permission -> automatically DELETE WebAPI
     *
     * **BOTH (delete both sides):**
     * [FROM: WebAPI] ---> [TO: Permission]
     * DELETE WebAPI -> DELETE Permission
     * DELETE Permission -> DELETE WebAPI
     *
     * @param array $edges Array of edge documents returned by deleteEdges().
     * @param object $target The vertex model that triggered the deletion.
     *
     * @return void
     *
     * Example:
     * ```php
     * $edges = $edge->deleteEdges(vertex: $vertexKeys, init: [AQL::CONTEXT => $edge->from]);
     * $edge->purgeVertices($edges, $edge->from);
     * ```
     */
    private function purgeVertices( array $edges , object $target ) :void
    {
        if ( empty( $edges ) || !isset( $this->purge ) )
        {
            return;
        }

        $definitions = [] ;

        if ( ( $this->purge === Purge::OUTBOUND || $this->purge === Purge::BOTH )
            && $target === $this->from
            && isset( $this->to )
        )
        {
            $definitions[] =
            [
                Arango::MODEL => $this->to,
                Arango::IDS   => extractToIDs( $edges ) ,
            ];
        }

        if ( ( $this->purge === Purge::INBOUND || $this->purge === Purge::BOTH )
            && $target === $this->to
            && isset( $this->from )
        )
        {
            $definitions[] =
            [
                Arango::MODEL => $this->from,
                Arango::IDS   => extractFromIDs( $edges ) ,
            ];
        }

        foreach ( $definitions as $definition )
        {
            $model = $definition[ Arango::MODEL ] ?? null ;
            $model?->delete
            ([
                Arango::KEY   => Schema::_ID ,
                Arango::VALUE => $definition[ Arango::IDS ] ?? []
            ]);
        }
    }
}
