<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Return the bitwise XOR (exclusive or) of its operands.
 *
 * Wraps the ArangoDB AQL function `BIT_XOR()`, which has two forms:
 * - an array of numbers: `BIT_XOR(numbersArray)`,
 * - two number operands: `BIT_XOR(value1, value2)` (pass `$value2`).
 *
 * PHP arrays are emitted as JSON literals; strings are passed through as raw AQL expressions.
 *
 * Example AQL usage:
 * ```aql
 * BIT_XOR([1, 2, 3])   // returns 0
 * BIT_XOR(1, 5)        // returns 4
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitXor;
 *
 * $expr = bitXor([1, 2, 3]);   // 'BIT_XOR([1,2,3])'
 * $expr = bitXor(1, 5);        // 'BIT_XOR(1,5)'
 * ```
 *
 * @param string|int|array    $values The numbers array, or the first operand when `$value2` is given.
 * @param string|int|null     $value2 The second operand (selects the two-number form).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_xor
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitXor( string|int|array $values , string|int|null $value2 = null ) : string
{
    if ( $value2 !== null )
    {
        return func( BitFunction::BIT_XOR , [ $values , $value2 ] ) ;
    }
    return func( BitFunction::BIT_XOR , is_array( $values ) ? json_encode( $values ) : $values ) ;
}
