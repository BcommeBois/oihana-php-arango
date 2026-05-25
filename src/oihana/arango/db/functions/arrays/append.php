<?php

namespace oihana\arango\db\functions\arrays;

use oihana\enums\Boolean;
use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Append all elements of one array to another array.
 *
 * This helper wraps the ArangoDB AQL function `APPEND(anyArray, values, unique)`
 * to concatenate arrays. All values from the second argument are appended to
 * the end of the first array. If `unique` is set to true, only values that are
 * not already present in the target array are appended.
 *
 * Example AQL usage:
 * ```aql
 * APPEND(doc.tags, ["new", "tags"])      // appends values to doc.tags
 * APPEND(doc.tags, other.tags, true)       // appends only values not present yet
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\append;
 *
 * $expr = append('doc.tags', '["a", "b"]', true);
 * // Produces: 'APPEND(doc.tags, ["a", "b"], true)'
 * ```
 *
 * @param mixed $anyArray The AQL array expression to append values to.
 * @param mixed $values   The AQL array or value(s) to append.
 * @param bool  $unique   When true, only append values that are not already in the array.
 * @return string The formatted AQL expression (e.g., 'APPEND(doc.tags, other.tags, true)').
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#append
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * author Marc Alcaraz
 */
function append( mixed $anyArray , mixed $values , bool $unique = false ) : string
{
    $expression = [ $anyArray , $values ] ;
    if( $unique )
    {
        $expression[] = Boolean::TRUE ;
    }
    return func( ArrayFunction::APPEND , $expression ) ;
}
