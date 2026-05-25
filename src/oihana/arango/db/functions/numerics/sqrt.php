<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the square root of a value.
 *
 * This helper wraps the ArangoDB AQL function `SQRT(value)` which returns
 * the square root of a value. The value must be non-negative, otherwise
 * it returns null.
 *
 * Example AQL usage:
 * ```aql
 * SQRT(0)  // returns 0
 * SQRT(1)  // returns 1
 * SQRT(4)  // returns 2
 * SQRT(9)  // returns 3
 * SQRT(-1) // returns null (invalid input)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\sqrt;
 *
 * $expr = sqrt(16);
 * // Produces: 'SQRT(16)'
 * ```
 *
 * @param string|int|float $value The input value (must be non-negative).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#sqrt
 * @see pow() For raising to a power (SQRT(x) = POW(x, 0.5)).
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function sqrt( string|int|float $value ) : string
{
    return func( NumericFunction::SQRT , $value ) ;
}

