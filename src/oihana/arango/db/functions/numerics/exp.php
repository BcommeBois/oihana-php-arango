<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return Euler's constant (e) raised to the power of a value.
 *
 * This helper wraps the ArangoDB AQL function `EXP(value)` which returns
 * Euler's constant (approximately 2.71828) raised to the power of the given value.
 * This is the inverse of the natural logarithm function.
 *
 * Example AQL usage:
 * ```aql
 * EXP(0)                        // returns 1
 * EXP(1)                        // returns 2.718281828459045 (e)
 * EXP(2)                        // returns 7.38905609893065 (e²)
 * EXP(-1)                       // returns 0.36787944117144233 (1/e)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\exp;
 *
 * $expr = exp(1);
 * // Produces: 'EXP(1)'
 * ```
 *
 * @param string|int|float $value The exponent value.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#exp
 *
 * @see exp2() For 2 raised to a power.
 * @see log() For the natural logarithm (inverse of EXP).
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function exp( string|int|float $value ) : string
{
    return func( NumericFunction::EXP , $value ) ;
}

