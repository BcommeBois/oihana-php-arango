<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Bitwise-shift the bits of a number to the right, keeping up to `bits` bits.
 *
 * Wraps the ArangoDB AQL function `BIT_SHIFT_RIGHT(value, shift, bits)`. Bits that
 * are shifted out past position 0 are discarded.
 *
 * Example AQL usage:
 * ```aql
 * BIT_SHIFT_RIGHT(16, 4, 8)   // returns 1
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitShiftRight;
 *
 * $expr = bitShiftRight(16, 4, 8);   // 'BIT_SHIFT_RIGHT(16,4,8)'
 * ```
 *
 * @param string|int $value The number to shift.
 * @param string|int $shift The number of positions to shift right.
 * @param string|int $bits  The number of bits to keep in the result (0 … 32).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_shift_right
 * @see bitShiftLeft()
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitShiftRight( string|int $value , string|int $shift , string|int $bits ) : string
{
    return func( BitFunction::BIT_SHIFT_RIGHT , [ $value , $shift , $bits ] ) ;
}
