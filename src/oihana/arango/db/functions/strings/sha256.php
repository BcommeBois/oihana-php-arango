<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Calculate the SHA256 checksum for text and return it in hexadecimal format.
 *
 * This helper wraps the ArangoDB AQL function `SHA256(text)` which calculates
 * the SHA256 checksum for the given text and returns it as a hexadecimal string.
 * SHA256 is a cryptographic hash function producing a 256-bit hash.
 *
 * Example AQL usage:
 * ```aql
 * SHA256("hello")               // returns "2cf24dba4fb601a80065e1c3b8b5c9e8b8b5c9e8b8b5c9e8b8b5c9e8b8b5c9e8"
 * SHA256("world")               // returns "486ea46224d1bb4fb680f34f7c9ad96a8f24ec88be73ea8e5a6c65260e9cb8a7"
 * SHA256(doc.content)           // returns SHA256 hash of content
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\sha256;
 *
 * $expr = sha256('doc.content');
 * // Produces: 'SHA256(doc.content)'
 * ```
 *
 * @param string $value String expression to calculate SHA256 hash for.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#sha256
 * @see sha1() For SHA1 hash.
 * @see sha512() For SHA512 hash.
 * @see md5() For MD5 hash.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function sha256( string $value ): string
{
    return func(StringFunction::SHA256 , $value ) ;
}

