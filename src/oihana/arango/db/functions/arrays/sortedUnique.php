<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Sort the elements of an array and remove duplicates.
 *
 * This helper wraps the ArangoDB AQL function `SORTED_UNIQUE(anyArray)` which
 * returns a new array with all elements sorted in ascending order and duplicates
 * removed. The original array is not modified.
 *
 * Example AQL usage:
 * ```aql
 * SORTED_UNIQUE([8, 4, 2, 10, 6, 2, 8, 6, 4])    // returns [2, 4, 6, 8, 10]
 * SORTED_UNIQUE(["c", "a", "b", "a", "c"])        // returns ["a", "b", "c"]
 * SORTED_UNIQUE(doc.tags)                         // sorts and deduplicates tags
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\sortedUnique;
 *
 * $expr = sortedUnique('[8,4,2,10,6,2,8,6,4]');
 * // Produces: 'SORTED_UNIQUE([8,4,2,10,6,2,8,6,4])'
 * ```
 *
 * @param mixed $anyArray Array expression to sort and deduplicate.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#sorted_unique
 * @see sorted() For sorting without removing duplicates.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function sortedUnique( mixed $anyArray ) : string
{
    return func( ArrayFunction::SORTED_UNIQUE , $anyArray ) ;
}

