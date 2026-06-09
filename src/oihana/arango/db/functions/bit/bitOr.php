<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Return the bitwise OR of its operands.
 *
 * Wraps the ArangoDB AQL function `BIT_OR()`, which has two forms:
 * - an array of numbers: `BIT_OR(numbersArray)`,
 * - two number operands: `BIT_OR(value1, value2)` (pass `$value2`).
 *
 * PHP arrays are emitted as JSON literals; strings are passed through as raw AQL expressions.
 *
 * Example AQL usage:
 * ```aql
 * BIT_OR([1, 4, 8, 16])   // returns 29
 * BIT_OR(1, 2)            // returns 3
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitOr;
 *
 * $expr = bitOr([1, 4, 8, 16]);   // 'BIT_OR([1,4,8,16])'
 * $expr = bitOr(1, 2);            // 'BIT_OR(1,2)'
 * ```
 *
 * @param string|int|array    $values The numbers array, or the first operand when `$value2` is given.
 * @param string|int|null     $value2 The second operand (selects the two-number form).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_or
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitOr( string|int|array $values , string|int|null $value2 = null ) : string
{
    if ( $value2 !== null )
    {
        return func( BitFunction::BIT_OR , [ $values , $value2 ] ) ;
    }
    return func( BitFunction::BIT_OR , is_array( $values ) ? json_encode( $values ) : $values ) ;
}
