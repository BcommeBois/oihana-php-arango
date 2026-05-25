<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return 2 raised to the power of a value.
 *
 * This helper wraps the ArangoDB AQL function `EXP2(value)` which returns
 * 2 raised to the power of the given value. This is useful for binary
 * operations and exponential growth calculations.
 *
 * Example AQL usage:
 * ```aql
 * EXP2(0)                       // returns 1
 * EXP2(1)                       // returns 2
 * EXP2(2)                       // returns 4
 * EXP2(3)                       // returns 8
 * EXP2(-1)                      // returns 0.5
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\exp2;
 *
 * $expr = exp2(3);
 * // Produces: 'EXP2(3)'
 * ```
 *
 * @param string|int|float $value The exponent value.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#exp2
 * @see exp() For Euler's constant raised to a power.
 * @see log2() For the base-2 logarithm (inverse of EXP2).
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function exp2( string|int|float $value ) : string
{
    return func( NumericFunction::EXP2 , $value ) ;
}

