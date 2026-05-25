<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Remove whitespace from the end of a string.
 *
 * This helper wraps the ArangoDB AQL function `RTRIM(value, chars)` which removes
 * whitespace characters from the end (right side) of a string. You can specify
 * custom characters to remove instead of the default whitespace.
 *
 * Example AQL usage:
 * ```aql
 * RTRIM("  hello world  ")      // returns "  hello world"
 * RTRIM("***hello***", "*")     // returns "***hello"
 * RTRIM("hello  ")              // returns "hello"
 * RTRIM(doc.title)              // removes trailing whitespace from title
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\rtrim;
 *
 * $expr = rtrim('doc.title');
 * // Produces: 'RTRIM(doc.title)'
 *
 * $expr = rtrim('doc.title', '"*"');
 * // Produces: 'RTRIM(doc.title, "*")'
 * ```
 *
 * @param string $value String expression to trim from the right.
 * @param string|null $chars Optional characters to remove (defaults to whitespace).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#rtrim
 * @see ltrim() For trimming from the left.
 * @see trim() For trimming from both sides.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function rtrim( string $value , ?string $chars = null ): string
{
    return func(StringFunction::RTRIM , [ $value , $chars ] ) ;
}

