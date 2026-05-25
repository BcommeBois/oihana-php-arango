<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Calculate the FNV-1A 64-bit hash for text and return it in hexadecimal format.
 *
 * This helper wraps the ArangoDB AQL function `FNV64(text)` which calculates
 * the FNV-1A 64-bit hash for the given text and returns it as a hexadecimal string.
 * FNV (Fowler-Noll-Vo) is a fast, non-cryptographic hash function.
 *
 * Example AQL usage:
 * ```aql
 * FNV64("hello")                // returns "a430d84680aabd0b"
 * FNV64("world")                // returns "a430d84680aabd0b"
 * FNV64(doc.content)            // returns FNV64 hash of content
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\fnv64;
 *
 * $expr = fnv64('doc.content');
 * // Produces: 'FNV64(doc.content)'
 * ```
 *
 * @param string $value String expression to calculate FNV64 hash for.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#fnv64
 * @see md5() For MD5 hash.
 * @see sha1() For SHA1 hash.
 * @see crc32() For CRC32 checksum.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function fnv64( string $value ): string
{
    return func(StringFunction::FNV64 , $value ) ;
}

