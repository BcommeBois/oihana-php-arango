<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the base-10 logarithm of a value.
 *
 * This helper wraps the ArangoDB AQL function `LOG10(value)` which returns
 * the base-10 logarithm (common logarithm) of a value. The value must be
 * greater than 0, otherwise it returns null.
 *
 * Example AQL usage:
 * ```aql
 * LOG10(1)                      // returns 0
 * LOG10(10)                     // returns 1
 * LOG10(100)                    // returns 2
 * LOG10(1000)                   // returns 3
 * LOG10(0)                      // returns null (invalid input)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\log10;
 *
 * $expr = log10(100);
 * // Produces: 'LOG10(100)'
 * ```
 *
 * @param string|int|float $value The input value (must be greater than 0).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#log10
 * @see log() For the natural logarithm.
 * @see log2() For the base-2 logarithm.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function log10( string|int|float $value ) : string
{
    return func( NumericFunction::LOG10 , $value ) ;
}

