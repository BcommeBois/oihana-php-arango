<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the integer closest but not greater than the given value.
 *
 * This helper wraps the ArangoDB AQL function `FLOOR(value)` which rounds down
 * a number to the nearest integer that is less than or equal to the value.
 *
 * Example AQL usage:
 * ```aql
 * FLOOR(4.1)                    // returns 4
 * FLOOR(4.9)                    // returns 4
 * FLOOR(-4.1)                   // returns -5
 * FLOOR(doc.price)              // rounds down the price
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\floor;
 *
 * $expr = floor(4.9);
 * // Produces: 'FLOOR(4.9)'
 * ```
 *
 * @param string|int|float $value Any number to round down.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#floor
 * @see ceil() For rounding up.
 * @see round() For rounding to nearest.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function floor( string|int|float $value ) : string
{
    return func( NumericFunction::FLOOR , $value ) ;
}

