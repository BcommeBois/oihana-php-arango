<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Get the last element of an array.
 *
 * This helper wraps the ArangoDB AQL function `LAST(anyArray)` which returns
 * the last element of the given array. If the array is empty, it returns null.
 *
 * Example AQL usage:
 * ```aql
 * LAST([1, 2, 3])             // returns 3
 * LAST(doc.items)             // returns last item from doc.items
 * LAST([])                    // returns null
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\last;
 *
 * $expr = last('[1,2,3]');
 * // Produces: 'LAST([1,2,3])'
 * ```
 *
 * @param mixed $anyArray Array expression to get the last element from.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#last
 * @see first() For getting the first element.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function last( mixed $anyArray ) : string
{
    return func( ArrayFunction::LAST , $anyArray ) ;
}

