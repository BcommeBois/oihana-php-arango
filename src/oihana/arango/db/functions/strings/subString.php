<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return a substring of a string.
 *
 * This helper wraps the ArangoDB AQL function `SUBSTRING(value, offset, length)`
 * which extracts a substring from the given string starting at the specified
 * offset with the optional length. Negative offsets start from the end of the string.
 *
 * Example AQL usage:
 * ```aql
 * SUBSTRING("hello world", 0, 5)    // returns "hello"
 * SUBSTRING("hello world", 6)       // returns "world"
 * SUBSTRING("hello world", -5)      // returns "world" (from end)
 * SUBSTRING("hello world", 0, 3)    // returns "hel"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\subString;
 *
 * $expr = subString('doc.text', 0, 10);
 * // Produces: 'SUBSTRING(doc.text, 0, 10)'
 * ```
 *
 * @param string $value String expression to extract substring from.
 * @param int $offset Starting position (0-based, negative values start from end).
 * @param int|null $length Optional length of substring (default: to end of string).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/3.12/aql/functions/string/#substring
 * @see left() For extracting from the beginning.
 * @see right() For extracting from the end.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function subString( string $value , int $offset , ?int $length = null ): string
{
    return func(StringFunction::SUBSTRING , [ $value , $offset , $length ] ) ;
}

