<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the leftmost characters of a string.
 *
 * This helper wraps the ArangoDB AQL function `LEFT(value, length)` which returns
 * the specified number of characters from the left (beginning) of the string.
 *
 * Example AQL usage:
 * ```aql
 * LEFT("hello world", 5)        // returns "hello"
 * LEFT("hello world", 0)        // returns ""
 * LEFT("hello world", 20)       // returns "hello world" (entire string)
 * LEFT(doc.title, 10)           // returns first 10 characters of title
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\left;
 *
 * $expr = left('doc.title', 10);
 * // Produces: 'LEFT(doc.title, 10)'
 * ```
 *
 * @param string $value String expression to extract characters from.
 * @param int $length Number of characters to return from the left.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#left
 * @see right() For extracting characters from the right.
 * @see subString() For extracting characters from any position.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function left( string $value , int $length ): string
{
    return func(StringFunction::LEFT , [ $value , $length ] ) ;
}

