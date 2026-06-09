<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Test whether the bit at the given (zero-based) position is set in a number.
 *
 * Wraps the ArangoDB AQL function `BIT_TEST(value, index)`.
 *
 * Example AQL usage:
 * ```aql
 * BIT_TEST(255, 0)   // returns true
 * BIT_TEST(0, 3)     // returns false
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitTest;
 *
 * $expr = bitTest(255, 0);   // 'BIT_TEST(255,0)'
 * ```
 *
 * @param string|int $value The number to test.
 * @param string|int $index The zero-based bit position to test.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_test
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitTest( string|int $value , string|int $index ) : string
{
    return func( BitFunction::BIT_TEST , [ $value , $index ] ) ;
}
