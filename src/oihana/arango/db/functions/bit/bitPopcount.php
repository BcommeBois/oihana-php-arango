<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Return the number of bits set to 1 in a number (population count).
 *
 * Wraps the ArangoDB AQL function `BIT_POPCOUNT(value)`.
 *
 * Example AQL usage:
 * ```aql
 * BIT_POPCOUNT(255)   // returns 8
 * BIT_POPCOUNT(69)    // returns 3
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitPopcount;
 *
 * $expr = bitPopcount(255);   // 'BIT_POPCOUNT(255)'
 * ```
 *
 * @param string|int $value The number whose set bits are counted.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_popcount
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitPopcount( string|int $value ) : string
{
    return func( BitFunction::BIT_POPCOUNT , $value ) ;
}
