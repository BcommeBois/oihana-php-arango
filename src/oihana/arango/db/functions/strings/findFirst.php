<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the position of the first occurrence of a substring in a string.
 *
 * This helper wraps the ArangoDB AQL function `FIND_FIRST(text, search, start, end)`
 * which returns the position of the first occurrence of the search string within
 * the text string. Positions start at 0. You can optionally limit the search
 * to a subset of the text using start and end parameters.
 *
 * Example AQL usage:
 * ```aql
 * FIND_FIRST("hello world", "world")        // returns 6
 * FIND_FIRST("hello world", "o")            // returns 4
 * FIND_FIRST("hello world", "x")            // returns -1 (not found)
 * FIND_FIRST("hello world", "o", 5)         // returns 7 (search from position 5)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\findFirst;
 *
 * $expr = findFirst('doc.text', '"world"', 0, null);
 * // Produces: 'FIND_FIRST(doc.text, "world", 0)'
 * ```
 *
 * @param string $value The text to search in (haystack).
 * @param string $search The substring to search for (needle).
 * @param int|null $start Optional start position to limit search (default: 0).
 * @param int|null $end Optional end position to limit search (default: end of string).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#find_first
 * @see findLast() For finding the last occurrence.
 * @see contains() For checking if string contains substring.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function findFirst( string $value , string $search , ?int $start , ?int $end ): string
{
    return func(StringFunction::FIND_FIRST , [ $value , $search , $start , $end ] ) ;
}

