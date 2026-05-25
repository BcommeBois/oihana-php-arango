<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the tangent of a value.
 *
 * This helper wraps the ArangoDB AQL function `TAN(value)` which returns
 * the tangent of a value in radians.
 *
 * Example AQL usage:
 * ```aql
 * TAN(0)                   // returns 0
 * TAN(0.7853981633974483)  // returns 1 (tan(π/4))
 * TAN(1.5707963267948966)  // returns a very large number (tan(π/2))
 * TAN(doc.angle)           // returns tangent of the angle
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\tan;
 *
 * $expr = tan(0);
 * // Produces: 'TAN(0)'
 * ```
 *
 * @param string|int|float $value The input value in radians.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#tan
 * @see atan() For the arctangent function.
 * @see sin() For the sine function.
 * @see cos() For the cosine function.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function tan( string|int|float $value ) : string
{
    return func( NumericFunction::TAN , $value ) ;
}

