<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Remove whitespace from the start of a string.
 *
 * This helper wraps the ArangoDB AQL function `LTRIM(value, chars)` which removes
 * whitespace characters from the beginning (left side) of a string. You can specify
 * custom characters to remove instead of the default whitespace.
 *
 * Example AQL usage:
 * ```aql
 * LTRIM("  hello world  ")      // returns "hello world  "
 * LTRIM("***hello***", "*")     // returns "hello***"
 * LTRIM("  hello")              // returns "hello"
 * LTRIM(doc.title)              // removes leading whitespace from title
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\ltrim;
 *
 * $expr = ltrim('doc.title');
 * // Produces: 'LTRIM(doc.title)'
 *
 * $expr = ltrim('doc.title', '"*"');
 * // Produces: 'LTRIM(doc.title, "*")'
 * ```
 *
 * @param string $value String expression to trim from the left.
 * @param string|null $chars Optional characters to remove (defaults to whitespace).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#ltrim
 * @see rtrim() For trimming from the right.
 * @see trim() For trimming from both sides.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function ltrim( string $value , ?string $chars = null ): string
{
    return func(StringFunction::LTRIM , [ $value , $chars ] ) ;
}

