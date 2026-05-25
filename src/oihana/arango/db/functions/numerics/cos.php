<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the cosine of a value.
 *
 * This helper wraps the ArangoDB AQL function `COS(value)` which returns
 * the cosine of a value in radians.
 *
 * Example AQL usage:
 * ```aql
 * COS(0)                  // returns 1
 * COS(1.5707963267948966) // returns 0 (cos(π/2))
 * COS(3.141592653589793)  // returns -1 (cos(π))
 * COS(doc.angle)          // returns cosine of the angle
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\cos;
 *
 * $expr = cos(0);
 * // Produces: 'COS(0)'
 * ```
 *
 * @param string|int|float $value The input value in radians.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#cos
 * @see acos() For the arccosine function.
 * @see sin() For the sine function.
 * @see tan() For the tangent function.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function cos( string|int|float $value ) : string
{
    return func( NumericFunction::COS , $value ) ;
}

