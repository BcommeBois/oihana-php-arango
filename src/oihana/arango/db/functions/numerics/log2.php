<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the base-2 logarithm of a value.
 *
 * This helper wraps the ArangoDB AQL function `LOG2(value)` which returns
 * the base-2 logarithm of a value. The value must be greater than 0,
 * otherwise it returns null.
 *
 * Example AQL usage:
 * ```aql
 * LOG2(1) // returns 0
 * LOG2(2) // returns 1
 * LOG2(4) // returns 2
 * LOG2(8) // returns 3
 * LOG2(0) // returns null (invalid input)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\log2;
 *
 * $expr = log2(8);
 * // Produces: 'LOG2(8)'
 * ```
 *
 * @param string|int|float $value The input value (must be greater than 0).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#log2
 * @see log() For the natural logarithm.
 * @see log10() For the base-10 logarithm.
 * @see exp2() For 2 raised to a power (inverse of LOG2).
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function log2( string|int|float $value ) : string
{
    return func( NumericFunction::LOG2 , $value ) ;
}

