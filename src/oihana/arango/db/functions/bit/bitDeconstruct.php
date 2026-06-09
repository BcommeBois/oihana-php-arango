<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Deconstruct a number into the array of its set bit positions (zero-based).
 *
 * Wraps the ArangoDB AQL function `BIT_DECONSTRUCT(value)`. It is the inverse of
 * {@see bitConstruct()}.
 *
 * Example AQL usage:
 * ```aql
 * BIT_DECONSTRUCT(14)   // returns [1, 2, 3]
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitDeconstruct;
 *
 * $expr = bitDeconstruct(14);   // 'BIT_DECONSTRUCT(14)'
 * ```
 *
 * @param string|int $value The number to deconstruct.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_deconstruct
 * @see bitConstruct()
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitDeconstruct( string|int $value ) : string
{
    return func( BitFunction::BIT_DECONSTRUCT , $value ) ;
}
