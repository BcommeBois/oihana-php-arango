<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the integer closest to the given value.
 *
 * This helper wraps the ArangoDB AQL function `ROUND(value)` which rounds
 * a number to the nearest integer using standard rounding rules.
 *
 * Example AQL usage:
 * ```aql
 * ROUND(4.1)                    // returns 4
 * ROUND(4.5)                    // returns 5
 * ROUND(4.9)                    // returns 5
 * ROUND(doc.price)              // rounds the price to nearest integer
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\round;
 *
 * $expr = round(4.5);
 * // Produces: 'ROUND(4.5)'
 * ```
 *
 * @param string|int|float $value Any number to round to nearest integer.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#round
 * @see ceil() For rounding up.
 * @see floor() For rounding down.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function round( string|int|float $value ) : string
{
    return func( NumericFunction::ROUND , $value ) ;
}

