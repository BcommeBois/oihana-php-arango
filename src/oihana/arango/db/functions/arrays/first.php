<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Get the first element of an array.
 *
 * This helper wraps the ArangoDB AQL function `FIRST(anyArray)` which returns
 * the first element of the given array. If the array is empty, it returns null.
 *
 * Example AQL usage:
 * ```aql
 * FIRST([1, 2, 3])            // returns 1
 * FIRST(doc.items)            // returns first item from doc.items
 * FIRST([])                   // returns null
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\first;
 *
 * $expr = first('[1,2,3]');
 * // Produces: 'FIRST([1,2,3])'
 * ```
 *
 * @param mixed $anyArray Array expression to get the first element from.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#first
 * @see last() For getting the last element.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function first( mixed $anyArray ) : string
{
    return func( ArrayFunction::FIRST ,  $anyArray ) ;
}

