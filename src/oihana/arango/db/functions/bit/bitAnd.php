<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Return the bitwise AND of its operands.
 *
 * Wraps the ArangoDB AQL function `BIT_AND()`, which has two forms:
 * - an array of numbers: `BIT_AND(numbersArray)`,
 * - two number operands: `BIT_AND(value1, value2)` (pass `$value2`).
 *
 * PHP arrays are emitted as JSON literals; strings are passed through as raw AQL expressions.
 *
 * Example AQL usage:
 * ```aql
 * BIT_AND([1, 4, 8, 16])   // returns 0
 * BIT_AND(127, 255)        // returns 127
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitAnd;
 *
 * $expr = bitAnd([1, 4, 8, 16]);   // 'BIT_AND([1,4,8,16])'
 * $expr = bitAnd(127, 255);        // 'BIT_AND(127,255)'
 * ```
 *
 * @param string|int|array    $values The numbers array, or the first operand when `$value2` is given.
 * @param string|int|null     $value2 The second operand (selects the two-number form).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_and
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitAnd( string|int|array $values , string|int|null $value2 = null ) : string
{
    if ( $value2 !== null )
    {
        return func( BitFunction::BIT_AND , [ $values , $value2 ] ) ;
    }
    return func( BitFunction::BIT_AND , is_array( $values ) ? json_encode( $values ) : $values ) ;
}
