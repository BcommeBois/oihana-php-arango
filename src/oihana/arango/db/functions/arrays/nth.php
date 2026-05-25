<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Get the element at the given position in an array.
 *
 * This helper wraps the ArangoDB AQL function `NTH(anyArray, position)` which
 * returns the element at the specified zero-based position in the array.
 * If the position is out of bounds, it returns null.
 *
 * Note: Negative positions are not supported in ArangoDB AQL.
 *
 * Example AQL usage:
 * ```aql
 * NTH([2, 4, 6, 8], 0)        // returns 2 (first element)
 * NTH([2, 4, 6, 8], 2)        // returns 6 (third element)
 * NTH([2, 4, 6, 8], 10)       // returns null (out of bounds)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\nth;
 *
 * $expr = nth('[2,4,6,8]', 2);
 * // Produces: 'NTH([2,4,6,8],2)'
 * ```
 *
 * @param mixed $anyArray Array expression to get element from.
 * @param int $position Zero-based position of the element to retrieve.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#nth
 * @see first() For getting the first element.
 * @see last() For getting the last element.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function nth( mixed $anyArray , int $position ) : string
{
    return func( ArrayFunction::NTH , [ $anyArray , $position ] ) ;
}

