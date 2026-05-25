<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the position of the last occurrence of a substring in a string.
 *
 * This helper wraps the ArangoDB AQL function `FIND_LAST(text, search, start, end)`
 * which returns the position of the last occurrence of the search string within
 * the text string. Positions start at 0. You can optionally limit the search
 * to a subset of the text using start and end parameters.
 *
 * Example AQL usage:
 * ```aql
 * FIND_LAST("hello world", "o")             // returns 7
 * FIND_LAST("hello world", "l")             // returns 9
 * FIND_LAST("hello world", "x")             // returns -1 (not found)
 * FIND_LAST("hello world", "o", 0, 5)       // returns 4 (search in first 5 chars)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\findLast;
 *
 * $expr = findLast('doc.text', '"o"', 0, null);
 * // Produces: 'FIND_LAST(doc.text, "o", 0)'
 * ```
 *
 * @param string $value The text to search in (haystack).
 * @param string $search The substring to search for (needle).
 * @param int|null $start Optional start position to limit search (default: 0).
 * @param int|null $end Optional end position to limit search (default: end of string).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#find_last
 * @see findFirst() For finding the first occurrence.
 * @see contains() For checking if string contains substring.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function findLast( string $value , string $search , ?int $start , ?int $end ): string
{
    return func(StringFunction::FIND_LAST , [ $value , $search , $start , $end ] ) ;
}

