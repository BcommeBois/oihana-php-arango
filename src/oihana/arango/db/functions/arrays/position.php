<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use oihana\enums\Boolean;
use oihana\enums\Char;
use function oihana\core\strings\func;

/**
 * Returns the position of a value in an array.
 *
 * This helper wraps the ArangoDB AQL function `POSITION(anyArray, search, returnIndex)`
 * which searches for a value in an array and returns either its position or a boolean
 * indicating whether the value was found.
 *
 * Example AQL usage:
 * ```aql
 * POSITION([2, 4, 6, 8], 4)           // returns 1 (position of 4)
 * POSITION([2, 4, 6, 8], 4, true)     // returns 1 (same as above)
 * POSITION([2, 4, 6, 8], 5, false)    // returns false (not found)
 * POSITION([2, 4, 6, 8], 5)           // returns false (default behavior)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\position;
 *
 * $expr = position('[2,4,6,8]', 4);
 * // Produces: 'POSITION([2,4,6,8],4)'
 *
 * $expr = position('[2,4,6,8]', 4, true);
 * // Produces: 'POSITION([2,4,6,8],4,true)'
 * ```
 *
 * @param mixed $anyArray Array expression to search in.
 * @param int|string $search Value to search for in the array.
 * @param bool $returnIndex When true, returns the index position; when false, returns boolean.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#position
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function position( mixed $anyArray , int|string $search , bool $returnIndex = false ) : string
{
    return func( ArrayFunction::POSITION , [ $anyArray , $search , $returnIndex ? Boolean::TRUE : Char::EMPTY ] ) ;
}

