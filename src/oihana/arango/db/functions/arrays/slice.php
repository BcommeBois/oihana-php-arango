<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Extract a slice from an array.
 *
 * This helper wraps the ArangoDB AQL function `SLICE(anyArray, start, length)`
 * which extracts a portion of an array starting from the specified index.
 * The length parameter is optional - if not provided, all elements from
 * the start position to the end are included.
 *
 * Example AQL usage:
 * ```aql
 * SLICE([1, 2, 3, 4, 5], 1, 2)       // returns [2, 3]
 * SLICE([1, 2, 3, 4, 5], 2)          // returns [3, 4, 5]
 * SLICE([1, 2, 3, 4, 5], 0, 3)       // returns [1, 2, 3]
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\slice;
 *
 * $expr = slice('[1, 2, 3]', 0, 1);
 * // Produces: 'SLICE([1, 2, 3],0,1)'
 *
 * $expr = slice('doc.items', 5);
 * // Produces: 'SLICE(doc.items,5)'
 * ```
 *
 * @param mixed $anyArray Array expression to slice.
 * @param int $start Starting index (zero-based) for the slice.
 * @param int|null $length Optional length of the slice (null = to end of array).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#slice
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function slice( mixed $anyArray , int $start , ?int $length ) : string
{
    return func( ArrayFunction::SLICE , [ $anyArray , $start , $length ] ) ;
}

