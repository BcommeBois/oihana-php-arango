<?php

namespace oihana\arango\models\helpers;

use org\schema\constants\Schema;

/**
 * Extract unique "_from" vertex IDs from a list of edge documents.
 *
 * This helper function is a shortcut for `extractVertexIds()` with `$side` fixed to `Schema::_FROM`.
 * It returns a unique list of vertex IDs from the "_from" field in an array of edge documents
 * (objects or associative arrays).
 *
 * @param array $edges Array of edge objects or associative arrays containing `_from` and `_to`.
 *
 * @return array Unique list of "_from" vertex IDs.
 *
 * @example
 * ```php
 * use oihana\arango\models\helpers\extractFromIds;
 * use org\schema\constants\Schema;
 *
 * $edges = [
 *     (object) ['_from' => 'apis/1', '_to' => 'permissions/1'],
 *     (object) ['_from' => 'apis/1', '_to' => 'permissions/2'],
 *     ['_from' => 'apis/2', '_to' => 'permissions/1'],
 * ];
 *
 * $fromIds = extractFromIds($edges);
 * // Result: ['apis/1', 'apis/2']
 * ```
 *
 * @author Marc Alcaraz (eKameleon)
 * @package oihana\arango\models\helpers
 * @version 1.0.0
 */
function extractFromIDs( array $edges ): array
{
    return extractVertexIDs( $edges , Schema::_FROM ) ;
}