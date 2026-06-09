<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Return the bitwise negation of a number, keeping up to `bits` bits.
 *
 * Wraps the ArangoDB AQL function `BIT_NEGATE(value, bits)`.
 *
 * Example AQL usage:
 * ```aql
 * BIT_NEGATE(0, 8)     // returns 255
 * BIT_NEGATE(255, 8)   // returns 0
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitNegate;
 *
 * $expr = bitNegate(0, 8);   // 'BIT_NEGATE(0,8)'
 * ```
 *
 * @param string|int $value The number to negate.
 * @param string|int $bits  The number of bits to keep (0 … 32).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_negate
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitNegate( string|int $value , string|int $bits ) : string
{
    return func( BitFunction::BIT_NEGATE , [ $value , $bits ] ) ;
}
