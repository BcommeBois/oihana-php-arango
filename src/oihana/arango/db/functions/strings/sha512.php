<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Calculate the SHA512 checksum for text and return it in hexadecimal format.
 *
 * This helper wraps the ArangoDB AQL function `SHA512(text)` which calculates
 * the SHA512 checksum for the given text and returns it as a hexadecimal string.
 * SHA512 is a cryptographic hash function producing a 512-bit hash.
 *
 * Example AQL usage:
 * ```aql
 * SHA512("hello")               // returns a 128-character hexadecimal string
 * SHA512("world")               // returns a 128-character hexadecimal string
 * SHA512(doc.content)           // returns SHA512 hash of content
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\sha512;
 *
 * $expr = sha512('doc.content');
 * // Produces: 'SHA512(doc.content)'
 * ```
 *
 * @param string $value String expression to calculate SHA512 hash for.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#sha512
 * @see sha1() For SHA1 hash.
 * @see sha256() For SHA256 hash.
 * @see md5() For MD5 hash.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function sha512( string $value ): string
{
    return func(StringFunction::SHA512 , $value ) ;
}

