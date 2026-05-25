<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Generate a pseudo-random token string with the specified length.
 *
 * This helper wraps the ArangoDB AQL function `RANDOM_TOKEN(length)` which generates
 * a pseudo-random token string. The algorithm for token generation should be treated
 * as opaque. The length must be between 0 and 65536.
 *
 * Example AQL usage:
 * ```aql
 * RANDOM_TOKEN(8)               // returns a random 8-character string
 * RANDOM_TOKEN(16)              // returns a random 16-character string
 * RANDOM_TOKEN(0)               // returns "" (empty string)
 * RANDOM_TOKEN(32)              // returns a random 32-character string
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\randomToken;
 *
 * $expr = randomToken(16);
 * // Produces: 'RANDOM_TOKEN(16)'
 * ```
 *
 * @param int $length Desired string length for the token (0-65536).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#random_token
 * @see uuid() For generating UUIDs.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function randomToken( int $length ): string
{
    return func(StringFunction::RANDOM_TOKEN , $length ) ;
}

