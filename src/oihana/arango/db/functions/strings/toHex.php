<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the hexadecimal representation of a value.
 *
 * This helper wraps the ArangoDB AQL function `TO_HEX(value)` which converts
 * the input value to its hexadecimal string representation. This is useful for
 * encoding binary data or converting numbers to hex format.
 *
 * Example AQL usage:
 * ```aql
 * TO_HEX("hello")               // returns "68656c6c6f"
 * TO_HEX("world")               // returns "776f726c64"
 * TO_HEX(doc.data)              // returns hexadecimal representation of data
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\toHex;
 *
 * $expr = toHex('doc.data');
 * // Produces: 'TO_HEX(doc.data)'
 * ```
 *
 * @param string $value String expression to convert to hexadecimal.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/3.12/aql/functions/string/#to_hex
 * @see toBase64() For Base64 encoding.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function toHex( string $value ): string
{
    return func(StringFunction::TO_HEX , $value ) ;
}

