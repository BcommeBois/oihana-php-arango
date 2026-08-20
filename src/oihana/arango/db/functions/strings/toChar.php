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
 * The codepoint may be a literal number **or any AQL expression producing one** —
 * a document attribute, most often — so the helper serves both a fixed character
 * and a per-document one:
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\toChar;
 *
 * $expr = toChar( 65 );
 * // Produces: 'TO_CHAR(65)'
 *
 * $expr = toChar( 'doc.codepoint' );
 * // Produces: 'TO_CHAR(doc.codepoint)'
 * ```
 *
 * The union is `string|int|float`, aligned on the numeric helpers ({@see \oihana\arango\db\functions\numerics\abs()},
 * {@see \oihana\arango\db\functions\numerics\ceil()}, …). A fractional codepoint names no character, but refusing
 * `float` while accepting `string` would guard nothing — `toChar('65.5')` would sail
 * straight through the narrower union anyway. The value is emitted as written; it is
 * ArangoDB that decides what a non-integer codepoint means.
 *
 * @param string|int|float $codepoint Unicode codepoint to convert to character, or an AQL expression producing one.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/3.12/aql/functions/string/#to_char
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function toChar( string|int|float $codepoint ): string
{
    return func(StringFunction::TO_CHAR , $codepoint ) ;
}

