<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the sine of a value.
 *
 * This helper wraps the ArangoDB AQL function `SIN(value)` which returns
 * the sine of a value in radians.
 *
 * Example AQL usage:
 * ```aql
 * SIN(0)                  // returns 0
 * SIN(1.5707963267948966) // returns 1 (sin(π/2))
 * SIN(3.141592653589793)  // returns 0 (sin(π))
 * SIN(doc.angle)          // returns sine of the angle
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\sin;
 *
 * $expr = sin(0);
 * // Produces: 'SIN(0)'
 * ```
 *
 * @param string|int|float $value The input value in radians.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#sin
 * @see asin() For the arcsine function.
 * @see cos() For the cosine function.
 * @see tan() For the tangent function.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function sin( string|int|float $value ) : string
{
    return func( NumericFunction::SIN , $value ) ;
}

