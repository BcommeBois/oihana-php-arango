<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the natural logarithm of a value.
 *
 * This helper wraps the ArangoDB AQL function `LOG(value)` which returns
 * the natural logarithm (base e) of a value. The value must be greater than 0,
 * otherwise it returns null.
 *
 * Example AQL usage:
 * ```aql
 * LOG(1)                 // returns 0
 * LOG(2.718281828459045) // returns 1 (ln(e) = 1)
 * LOG(10)                // returns 2.302585092994046
 * LOG(0)                 // returns null (invalid input)
 * LOG(-1)                // returns null (invalid input)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\log;
 *
 * $expr = log(10);
 * // Produces: 'LOG(10)'
 * ```
 *
 * @param string|int|float $value The input value (must be greater than 0).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#log
 * @see log2() For the base-2 logarithm.
 * @see log10() For the base-10 logarithm.
 * @see exp() For the exponential function (inverse of LOG).
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function log( string|int|float $value ) : string
{
    return func( NumericFunction::LOG , $value ) ;
}

