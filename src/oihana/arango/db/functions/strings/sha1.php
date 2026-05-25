<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Calculate the SHA1 checksum for text and return it in hexadecimal format.
 *
 * This helper wraps the ArangoDB AQL function `SHA1(text)` which calculates
 * the SHA1 checksum for the given text and returns it as a hexadecimal string.
 * SHA1 is a cryptographic hash function producing a 160-bit hash.
 *
 * Example AQL usage:
 * ```aql
 * SHA1("hello")                 // returns "aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d"
 * SHA1("world")                 // returns "7c211433f02071597741e6ff5a8ea34789abbf43"
 * SHA1(doc.content)             // returns SHA1 hash of content
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\sha1;
 *
 * $expr = sha1('doc.content');
 * // Produces: 'SHA1(doc.content)'
 * ```
 *
 * @param string $value String expression to calculate SHA1 hash for.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#sha1
 * @see sha256() For SHA256 hash.
 * @see sha512() For SHA512 hash.
 * @see md5() For MD5 hash.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function sha1( string $value ): string
{
    return func(StringFunction::SHA1 , $value ) ;
}

