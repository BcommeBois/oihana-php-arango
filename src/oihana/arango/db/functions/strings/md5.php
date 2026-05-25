<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Calculate the MD5 checksum for text and return it in hexadecimal format.
 *
 * This helper wraps the ArangoDB AQL function `MD5(text)` which calculates
 * the MD5 checksum for the given text and returns it as a hexadecimal string.
 * MD5 is a widely used cryptographic hash function producing a 128-bit hash.
 *
 * Example AQL usage:
 * ```aql
 * MD5("hello")     // returns "5d41402abc4b2a76b9719d911017c592"
 * MD5("world")     // returns "7d865e959b2466918c9863afca942d0fb"
 * MD5(doc.content) // returns MD5 hash of content
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\md5;
 *
 * $expr = md5('doc.content');
 * // Produces: 'MD5(doc.content)'
 * ```
 *
 * @param string $value String expression to calculate MD5 hash for.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#md5
 * @see sha1() For SHA1 hash.
 * @see sha256() For SHA256 hash.
 * @see crc32() For CRC32 checksum.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function md5( string $value ): string
{
    return func(StringFunction::MD5 , $value ) ;
}

