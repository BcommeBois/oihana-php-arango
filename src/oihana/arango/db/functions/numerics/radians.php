<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Convert an angle from degrees to radians.
 *
 * This helper wraps the ArangoDB AQL function `RADIANS(deg)` which converts
 * an angle from degrees to radians using the conversion factor 180 degrees = π radians.
 *
 * Example AQL usage:
 * ```aql
 * RADIANS(0)   // returns 0
 * RADIANS(90)  // returns 1.5707963267948966 (π/2)
 * RADIANS(180) // returns 3.141592653589793 (π)
 * RADIANS(360) // returns 6.283185307179586 (2π)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\radians;
 *
 * $expr = radians(90);
 * // Produces: 'RADIANS(90)'
 * ```
 *
 * @param string|int|float $deg The input value in degrees.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#radians
 * @see degrees() For converting radians to degrees.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function radians( string|int|float $deg ) : string
{
    return func( NumericFunction::RADIANS , $deg ) ;
}

