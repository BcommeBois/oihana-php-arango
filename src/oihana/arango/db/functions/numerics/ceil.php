<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the integer closest but not less than the given value.
 *
 * This helper wraps the ArangoDB AQL function `CEIL(value)` which rounds up
 * a number to the nearest integer that is greater than or equal to the value.
 *
 * Example AQL usage:
 * ```aql
 * CEIL(4.1)       // returns 5
 * CEIL(4.9)       // returns 5
 * CEIL(-4.1)      // returns -4
 * CEIL(doc.price) // rounds up the price
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\ceil;
 *
 * $expr = ceil(4.1);
 * // Produces: 'CEIL(4.1)'
 * ```
 *
 * @param string|int|float $value Any number to round up.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#ceil
 * @see floor() For rounding down.
 * @see round() For rounding to nearest.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function ceil( string|int|float $value ) : string
{
    return func( NumericFunction::CEIL , $value ) ;
}

