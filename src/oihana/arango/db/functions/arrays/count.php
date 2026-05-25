<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Count the number of elements in an array.
 *
 * This helper wraps the ArangoDB AQL function `COUNT(expression)` which is
 * an alias for `LENGTH(expression)`. It returns the number of elements in
 * the given array expression.
 *
 * Example AQL usage:
 * ```aql
 * COUNT(doc.tags)              // returns number of elements in doc.tags
 * COUNT([1, 2, 3, 4])         // returns 4
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\count;
 *
 * $expr = count('doc.items');
 * // Produces: 'COUNT(doc.items)'
 * ```
 *
 * @param mixed $expression Array expression to count elements of.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#count
 * @see length() For the equivalent LENGTH function.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function count( mixed $expression ) : string
{
    return func( ArrayFunction::COUNT , $expression ) ;
}

