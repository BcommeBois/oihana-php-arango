<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the absolute value of a number.
 *
 * This helper wraps the ArangoDB AQL function `ABS(value)` which returns the
 * absolute value (unsigned value) of a number, removing any negative sign.
 *
 * Example AQL usage:
 * ```aql
 * ABS(-5)                       // returns 5
 * ABS(5)                        // returns 5
 * ABS(doc.temperature)          // returns absolute temperature value
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\abs;
 *
 * $expr = abs(-10);
 * // Produces: 'ABS(-10)'
 * ```
 *
 * @param string|int|float $value Any number, positive or negative.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#abs
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function abs( string|int|float $value ) : string
{
    return func( NumericFunction::ABS , $value ) ;
}

