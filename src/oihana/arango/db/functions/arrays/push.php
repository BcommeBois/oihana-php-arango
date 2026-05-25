<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use oihana\enums\Boolean;
use oihana\enums\Char;
use function oihana\core\strings\func;

/**
 * Append a value to the end of an array.
 *
 * This helper wraps the ArangoDB AQL function `PUSH(anyArray, value, unique)` which
 * adds a value to the end of an array. If `unique` is true, the value is only added
 * if it's not already present in the array.
 *
 * Example AQL usage:
 * ```aql
 * PUSH([2, 4, 6], 8)          // returns [2, 4, 6, 8]
 * PUSH([2, 4, 6], 4)          // returns [2, 4, 6, 4]
 * PUSH([2, 4, 6], 4, true)    // returns [2, 4, 6] (4 already exists)
 * PUSH([2, 4, 6], 8, true)    // returns [2, 4, 6, 8] (8 is new)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\push;
 *
 * $expr = push('[2,4,6,8]', 4);
 * // Produces: 'PUSH([2,4,6,8],4)'
 *
 * $expr = push('[2,4,6,8]', 4, true);
 * // Produces: 'PUSH([2,4,6,8],4,true)'
 * ```
 *
 * @param mixed $anyArray Array expression to append value to.
 * @param mixed $value Value to append to the array.
 * @param bool $unique When true, value is added only if not already present.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#push
 *
 * @see unshift() For prepending values to the beginning.
 * @see append() For appending multiple values.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function push( mixed $anyArray , mixed $value , bool $unique = false ) : string
{
    return func( ArrayFunction::PUSH , [ $anyArray , $value , $unique ? Boolean::TRUE : Char::EMPTY ] ) ;
}

