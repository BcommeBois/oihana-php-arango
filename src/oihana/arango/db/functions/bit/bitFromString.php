<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Parse a bitstring (e.g. `"0101"`) into its numeric value.
 *
 * Wraps the ArangoDB AQL function `BIT_FROM_STRING(bitstring)`. It is the inverse of
 * {@see bitToString()}. The bitstring is emitted as a quoted string literal (`json_encode`),
 * so a literal such as `'0101'` produces valid AQL.
 *
 * Example AQL usage:
 * ```aql
 * BIT_FROM_STRING("0101")   // returns 5
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitFromString;
 *
 * $expr = bitFromString('0101');   // 'BIT_FROM_STRING("0101")'
 * ```
 *
 * @param string $bitstring The bitstring to parse (emitted as a quoted string literal).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_from_string
 * @see bitToString()
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitFromString( string $bitstring ) : string
{
    return func( BitFunction::BIT_FROM_STRING , json_encode( $bitstring ) ) ;
}
