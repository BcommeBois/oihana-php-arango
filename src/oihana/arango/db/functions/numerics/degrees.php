<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Convert an angle from radians to degrees.
 *
 * This helper wraps the ArangoDB AQL function `DEGREES(rad)` which converts
 * an angle from radians to degrees using the conversion factor π radians = 180 degrees.
 *
 * Example AQL usage:
 * ```aql
 * DEGREES(0)                  // returns 0
 * DEGREES(1.5707963267948966) // returns 90 (π/2 radians = 90 degrees)
 * DEGREES(3.141592653589793)  // returns 180 (π radians = 180 degrees)
 * DEGREES(6.283185307179586)  // returns 360 (2π radians = 360 degrees)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\degrees;
 *
 * $expr = degrees(1.5707963267948966);
 * // Produces: 'DEGREES(1.5707963267948966)'
 * ```
 *
 * @param string|int|float $rad The input value in radians.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#degrees
 * @see radians() For converting degrees to radians.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function degrees( string|int|float $rad ) : string
{
    return func( NumericFunction::DEGREES , $rad ) ;
}

