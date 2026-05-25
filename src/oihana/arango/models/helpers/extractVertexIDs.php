<?php

namespace oihana\arango\models\helpers;

use org\schema\constants\Schema;

/**
 * Extract unique vertex IDs from a list of edge documents.
 *
 * This helper function is used to extract either the "_from" or "_to" vertex IDs
 * from an array of edge documents (objects or arrays) and return only the unique IDs.
 *
 * @param array  $edges Array of edge objects or associative arrays containing `_from` and `_to`.
 * @param string $side  The side to extract, either `Schema::_FROM` or `Schema::_TO`.
 *
 * @return array Unique list of vertex IDs.
 *
 * @example
 * ```php
 * use oihana\arango\models\helpers\extractVertexIds;
 * use org\schema\constants\Schema;
 *
 * $edges =
 * [
 *     (object) ['_from' => 'apis/1', '_to' => 'permissions/1'],
 *     (object) ['_from' => 'apis/1', '_to' => 'permissions/2'],
 *     ['_from' => 'apis/2', '_to' => 'permissions/1'],
 * ];
 *
 * $toIds = extractVertexIds($edges, Schema::_TO);
 * // Result: ['permissions/1', 'permissions/2']
 *
 * $fromIds = extractVertexIds($edges, Schema::_FROM);
 * // Result: ['apis/1', 'apis/2']
 * ```
 *
 * @author Marc Alcaraz (eKameleon)
 * @package oihana\arango\models\helpers
 * @version 1.0.0
 */
function extractVertexIDs( array $edges , string $side ): array
{
    $side = $side == Schema::_FROM ? Schema::_FROM : Schema::_TO ;
    $ids  = array_values( array_unique( array_filter(  array_map
    (
        fn( $edge ) => is_object( $edge ) ? ( $edge->$side ?? null ) : ( $edge[ $side ] ?? null ) ,
        $edges
    ) ) ) ) ;
    sort($ids) ;
    return $ids;
}