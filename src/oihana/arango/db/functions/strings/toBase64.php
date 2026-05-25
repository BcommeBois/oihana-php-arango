<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the Base64 representation of a value.
 *
 * This helper wraps the ArangoDB AQL function `TO_BASE64(value)` which converts
 * the input value to its Base64 encoded string representation. Base64 encoding
 * is commonly used for encoding binary data in text format.
 *
 * Example AQL usage:
 * ```aql
 * TO_BASE64("hello")            // returns "aGVsbG8="
 * TO_BASE64("world")            // returns "d29ybGQ="
 * TO_BASE64(doc.data)           // returns Base64 encoded data
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\toBase64;
 *
 * $expr = toBase64('doc.data');
 * // Produces: 'TO_BASE64(doc.data)'
 * ```
 *
 * @param string $value String expression to encode to Base64.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/3.12/aql/functions/string/#to_base64
 * @see toHex() For hexadecimal encoding.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function toBase64( string $value ): string
{
    return func(StringFunction::TO_BASE64 , $value ) ;
}

