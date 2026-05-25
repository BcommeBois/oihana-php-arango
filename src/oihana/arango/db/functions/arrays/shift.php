<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Remove the first element of an array.
 *
 * This helper wraps the ArangoDB AQL function `SHIFT(anyArray)` which removes
 * and returns the first element of an array, modifying the original array.
 * If the array is empty, it returns null.
 *
 * Example AQL usage:
 * ```aql
 * SHIFT([1, 2, 3])            // returns 1, array becomes [2, 3]
 * SHIFT(doc.items)            // removes first item from doc.items
 * SHIFT([])                   // returns null
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\shift;
 *
 * $expr = shift('[1, 2, 3]');
 * // Produces: 'SHIFT([1, 2, 3])'
 * ```
 *
 * @param mixed $anyArray Array expression to remove first element from.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#shift
 *
 * @see unshift() For adding elements to the beginning.
 * @see push() For adding elements to the end.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function shift( mixed $anyArray ) : string
{
    return func( ArrayFunction::SHIFT ,  $anyArray ) ;
}

