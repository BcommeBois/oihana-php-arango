<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use oihana\enums\Boolean;
use oihana\enums\Char;
use function oihana\core\strings\func;

/**
 * Prepend a value to the beginning of an array.
 *
 * This helper wraps the ArangoDB AQL function `UNSHIFT(anyArray, value, unique)`
 * which adds a value to the beginning of an array. If `unique` is true, the value
 * is only added if it's not already present in the array.
 *
 * Example AQL usage:
 * ```aql
 * UNSHIFT([2, 4, 6], 1)           // returns [1, 2, 4, 6]
 * UNSHIFT([2, 4, 6], 4)           // returns [4, 2, 4, 6]
 * UNSHIFT([2, 4, 6], 4, true)     // returns [2, 4, 6] (4 already exists)
 * UNSHIFT([2, 4, 6], 1, true)     // returns [1, 2, 4, 6] (1 is new)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\unshift;
 *
 * $expr = unshift('[2,4,6,8]', 4);
 * // Produces: 'UNSHIFT([2,4,6,8], 4)'
 *
 * $expr = unshift('[2,4,6,8]', 4, true);
 * // Produces: 'UNSHIFT([2,4,6,8], 4, true)'
 * ```
 *
 * @param mixed $anyArray Array expression to prepend value to.
 * @param mixed $value Value to prepend to the array.
 * @param bool $unique When true, value is added only if not already present.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#unshift
 *
 * @see push() For appending values to the end.
 * @see shift() For removing elements from the beginning.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function unshift( mixed $anyArray , mixed $value , bool $unique = false ) : string
{
    return func( ArrayFunction::UNSHIFT , [ $anyArray , $value , $unique ? Boolean::TRUE : Char::EMPTY ] ) ;
}

