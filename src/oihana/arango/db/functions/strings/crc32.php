<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Calculate the CRC32 checksum for text and return it in hexadecimal format.
 *
 * This helper wraps the ArangoDB AQL function `CRC32(text)` which calculates
 * the CRC32 checksum for the given text and returns it as a hexadecimal string.
 * The polynomial used is 0x1EDC6F41 with initial value 0xFFFFFFFF and final XOR 0xFFFFFFFF.
 *
 * Example AQL usage:
 * ```aql
 * CRC32("hello")                // returns "3610a686"
 * CRC32("world")                // returns "4a17b156"
 * CRC32(doc.content)            // returns CRC32 checksum of content
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\crc32;
 *
 * $expr = crc32('doc.content');
 * // Produces: 'CRC32(doc.content)'
 * ```
 *
 * @param string $value String expression to calculate CRC32 checksum for.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#crc32
 * @see md5() For MD5 hash.
 * @see sha1() For SHA1 hash.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function crc32( string $value ): string
{
    return func(StringFunction::CRC32 , $value ) ;
}

