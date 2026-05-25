<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the arccosine of a value.
 *
 * This helper wraps the ArangoDB AQL function `ACOS(value)` which returns
 * the arccosine (inverse cosine) of a value in radians. The value must be
 * between -1 and 1 (inclusive), otherwise it returns null.
 *
 * Example AQL usage:
 * ```aql
 * ACOS(1)  // returns 0
 * ACOS(0)  // returns 1.5707963267948966 (π/2)
 * ACOS(-1) // returns 3.141592653589793 (π)
 * ACOS(2)  // returns null (out of range)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\acos;
 *
 * $expr = acos(0.5);
 * // Produces: 'ACOS(0.5)'
 * ```
 *
 * @param string|int|float $value The input value (must be between -1 and 1).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#acos
 * @see cos() For the cosine function.
 * @see asin() For the arcsine function.
 * @see atan() For the arctangent function.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function acos( string|int|float $value ) : string
{
    return func( NumericFunction::ACOS , $value ) ;
}

