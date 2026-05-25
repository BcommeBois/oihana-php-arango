<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Sort the elements of an array.
 *
 * This helper wraps the ArangoDB AQL function `SORTED(anyArray)` which returns
 * a new array with all elements sorted in ascending order. The original array
 * is not modified. Elements are sorted using ArangoDB's default comparison rules.
 *
 * Example AQL usage:
 * ```aql
 * SORTED([4, 1, 8, 2, 3])            // returns [1, 2, 3, 4, 8]
 * SORTED(["c", "a", "b"])            // returns ["a", "b", "c"]
 * SORTED(doc.numbers)                // sorts the numbers array
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\sorted;
 *
 * $expr = sorted('[4,1,8,2,3]');
 * // Produces: 'SORTED([4,1,8,2,3])'
 * ```
 *
 * @param mixed $anyArray Array expression to sort.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#sorted
 *
 * @see sortedUnique() For sorting and removing duplicates.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function sorted( mixed $anyArray ) : string
{
    return func( ArrayFunction::SORTED , $anyArray ) ;
}

