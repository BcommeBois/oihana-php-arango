<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the arctangent of a value.
 *
 * This helper wraps the ArangoDB AQL function `ATAN(value)` which returns
 * the arctangent (inverse tangent) of a value in radians. The value can be
 * any real number.
 *
 * Example AQL usage:
 * ```aql
 * ATAN(0)   // returns 0
 * ATAN(1)   // returns 0.7853981633974483 (π/4)
 * ATAN(-1)  // returns -0.7853981633974483 (-π/4)
 * ATAN(INF) // returns 1.5707963267948966 (π/2)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\atan;
 *
 * $expr = atan(1);
 * // Produces: 'ATAN(1)'
 * ```
 *
 * @param string|int|float $value The input value (any real number).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#atan
 * @see tan() For the tangent function.
 * @see atan2() For the two-parameter arctangent.
 * @see acos() For the arccosine function.
 * @see asin() For the arcsine function.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function atan( string|int|float $value ) : string
{
    return func( NumericFunction::ATAN , $value ) ;
}

