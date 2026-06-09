<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Return the bitstring representation of a number, padded to `bits` characters.
 *
 * Wraps the ArangoDB AQL function `BIT_TO_STRING(value, bits)`. It is the inverse of
 * {@see bitFromString()}.
 *
 * Example AQL usage:
 * ```aql
 * BIT_TO_STRING(7, 8)   // returns "00000111"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitToString;
 *
 * $expr = bitToString(7, 8);   // 'BIT_TO_STRING(7,8)'
 * ```
 *
 * @param string|int $value The number to represent.
 * @param string|int $bits  The number of bits (length of the resulting string, 0 … 32).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_to_string
 * @see bitFromString()
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitToString( string|int $value , string|int $bits ) : string
{
    return func( BitFunction::BIT_TO_STRING , [ $value , $bits ] ) ;
}
