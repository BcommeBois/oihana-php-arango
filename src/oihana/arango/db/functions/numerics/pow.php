<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the base raised to the power of the exponent.
 *
 * This helper wraps the ArangoDB AQL function `POW(base, exp)` which returns
 * the base raised to the power of the exponent. This is equivalent to base^exp.
 *
 * Example AQL usage:
 * ```aql
 * POW(2, 3)  // returns 8 (2³)
 * POW(10, 2) // returns 100 (10²)
 * POW(5, 0)  // returns 1 (any number to power 0)
 * POW(2, -1) // returns 0.5 (2⁻¹)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\pow;
 *
 * $expr = pow(2, 3);
 * // Produces: 'POW(2, 3)'
 * ```
 *
 * @param mixed $base The base value.
 * @param int   $exp The exponent value.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#pow
 * @see sqrt() For the square root (POW(x, 0.5)).
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function pow( mixed $base, int $exp ) : string
{
    return func( NumericFunction::POW , [ $base , $exp ] ) ;
}

