<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use oihana\enums\Char;
use function oihana\core\strings\compile;
use function oihana\core\strings\func;

/**
 * Return an array of numbers in the specified range.
 *
 * This helper wraps the ArangoDB AQL function `RANGE(start, stop, step)` which
 * generates an array of numbers from start to stop (exclusive) with the specified
 * step increment. The start and stop arguments are truncated to integers unless
 * a step argument is provided.
 *
 * Example AQL usage:
 * ```aql
 * RANGE(1, 5)                   // returns [1, 2, 3, 4]
 * RANGE(0, 10, 2)               // returns [0, 2, 4, 6, 8]
 * RANGE(5, 1, -1)               // returns [5, 4, 3, 2]
 * RANGE(1, 1)                   // returns [] (empty array)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\range;
 *
 * $expr = range(1, 5);
 * // Produces: 'RANGE(1, 5)'
 *
 * $expr = range(0, 10, 2);
 * // Produces: 'RANGE(0, 10, 2)'
 * ```
 *
 * @param int $start The starting value (inclusive).
 * @param int $stop The ending value (exclusive).
 * @param float $step The step increment (default: 1.0).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#range
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function range( int $start , int $stop , float $step = 1.0 ) : string
{
    return func( NumericFunction::RANGE , compile([ $start , $stop , $step == 1.0 ? Char::EMPTY : $step ], Char::COMMA) ) ;
}

