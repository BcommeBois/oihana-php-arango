<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the character with the specified Unicode codepoint.
 *
 * This helper wraps the ArangoDB AQL function `TO_CHAR(codepoint)` which returns
 * the character corresponding to the given Unicode codepoint. This is useful for
 * generating special characters or converting numeric codes to characters.
 *
 * Example AQL usage:
 * ```aql
 * TO_CHAR(65)                   // returns "A"
 * TO_CHAR(97)                   // returns "a"
 * TO_CHAR(8364)                 // returns "€" (Euro symbol)
 * TO_CHAR(32)                   // returns " " (space)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\toChar;
 *
 * $expr = toChar(65);
 * // Produces: 'TO_CHAR(65)'
 * ```
 *
 * @param int $codepoint Unicode codepoint to convert to character.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/3.12/aql/functions/string/#to_char
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function toChar( int $codepoint ): string
{
    return func(StringFunction::TO_CHAR , $codepoint ) ;
}

