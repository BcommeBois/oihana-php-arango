<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Count the number of distinct elements in an array.
 *
 * This helper wraps the ArangoDB AQL function `COUNT_DISTINCT(anyArray)` which
 * returns the number of unique elements in the given array, removing duplicates
 * before counting.
 *
 * Example AQL usage:
 * ```aql
 * COUNT_DISTINCT([1, 2, 3, 2, 1])     // returns 3 (unique elements: 1, 2, 3)
 * COUNT_DISTINCT(doc.tags)            // returns number of unique tags
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\arrays\countDistinct;
 *
 * $expr = countDistinct('[1,2,3,2]');
 * // Produces: 'COUNT_DISTINCT([1,2,3,2])'
 * ```
 *
 * @param mixed $anyArray Array expression to count distinct elements of.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#count_distinct
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function countDistinct( mixed $anyArray ) : string
{
    return func( ArrayFunction::COUNT_DISTINCT ,  $anyArray ) ;
}

