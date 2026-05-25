<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the arctangent of the quotient of y and x.
 *
 * This helper wraps the ArangoDB AQL function `ATAN2(y, x)` which returns
 * the arctangent of y/x in radians, using the signs of both arguments to
 * determine the quadrant of the result. This is more accurate than ATAN(y/x)
 * for determining the angle from the origin to a point.
 *
 * Example AQL usage:
 * ```aql
 * ATAN2(0, 1)   // returns 0
 * ATAN2(1, 1)   // returns 0.7853981633974483 (π/4)
 * ATAN2(1, 0)   // returns 1.5707963267948966 (π/2)
 * ATAN2(-1, -1) // returns -2.356194490192345 (-3π/4)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\atan2;
 *
 * $expr = atan2(1, 1);
 * // Produces: 'ATAN2(1, 1)'
 * ```
 *
 * @param string|int $y The y coordinate.
 * @param string|int $x The x coordinate.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#atan2
 * @see atan() For the single-parameter arctangent.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function atan2( string|int $y , string|int $x ) : string
{
    return func( NumericFunction::ATAN2 , [ $y , $x ] ) ;
}

