<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the arcsine of a value.
 *
 * This helper wraps the ArangoDB AQL function `ASIN(value)` which returns
 * the arcsine (inverse sine) of a value in radians. The value must be
 * between -1 and 1 (inclusive), otherwise it returns null.
 *
 * Example AQL usage:
 * ```aql
 * ASIN(0)  // returns 0
 * ASIN(1)  // returns 1.5707963267948966 (π/2)
 * ASIN(-1) // returns -1.5707963267948966 (-π/2)
 * ASIN(2)  // returns null (out of range)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\asin;
 *
 * $expr = asin(0.5);
 * // Produces: 'ASIN(0.5)'
 * ```
 *
 * @param string|int|float $value The input value (must be between -1 and 1).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#asin
 * @see sin() For the sine function.
 * @see acos() For the arccosine function.
 * @see atan() For the arctangent function.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function asin( string|int|float $value ) : string
{
    return func( NumericFunction::ASIN , $value ) ;
}

