<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the mathematical constant π (pi).
 *
 * This helper wraps the ArangoDB AQL function `PI()` which returns
 * the mathematical constant π (pi), approximately 3.141592653589793.
 *
 * Example AQL usage:
 * ```aql
 * PI()                          // returns 3.141592653589793
 * PI() * 2                      // returns 6.283185307179586 (2π)
 * PI() / 2                      // returns 1.5707963267948966 (π/2)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\pi;
 *
 * $expr = pi();
 * // Produces: 'PI()'
 * ```
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#pi
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function pi() : string
{
    return func( NumericFunction::PI ) ;
}

