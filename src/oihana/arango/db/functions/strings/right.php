<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the rightmost characters of a string.
 *
 * This helper wraps the ArangoDB AQL function `RIGHT(value, length)` which returns
 * the specified number of characters from the right (end) of the string.
 *
 * Example AQL usage:
 * ```aql
 * RIGHT("hello world", 5)       // returns "world"
 * RIGHT("hello world", 0)       // returns ""
 * RIGHT("hello world", 20)      // returns "hello world" (entire string)
 * RIGHT(doc.title, 10)          // returns last 10 characters of title
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\right;
 *
 * $expr = right('doc.title', 10);
 * // Produces: 'RIGHT(doc.title, 10)'
 * ```
 *
 * @param string $value String expression to extract characters from.
 * @param int $length Number of characters to return from the right.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#right
 * @see left() For extracting characters from the left.
 * @see subString() For extracting characters from any position.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function right( string $value , int $length ): string
{
    return func(StringFunction::RIGHT , [ $value , $length ] ) ;
}

