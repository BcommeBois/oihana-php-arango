<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Split a string into an array using a separator.
 *
 * This helper wraps the ArangoDB AQL function `SPLIT(value, separator, limit)`
 * which splits the given string into an array of strings using the specified
 * separator. You can optionally limit the number of splits.
 *
 * Example AQL usage:
 * ```aql
 * SPLIT("a,b,c", ",")           // returns ["a", "b", "c"]
 * SPLIT("hello world", " ")     // returns ["hello", "world"]
 * SPLIT("a,b,c", ",", 2)        // returns ["a", "b,c"] (limited to 2 parts)
 * SPLIT("hello", "")            // returns ["h", "e", "l", "l", "o"] (split by character)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\split;
 *
 * $expr = split('doc.text', '","', null);
 * // Produces: 'SPLIT(doc.text, ",", )'
 * ```
 *
 * @param string $value String expression to split.
 * @param string $separator Separator string to split on.
 * @param int|null $limit Optional limit for number of splits.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/3.12/aql/functions/string/#split
 * @see concat() For joining strings.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function split( string $value , string $separator , ?int $limit = null ): string
{
    return func(StringFunction::SPLIT , [ $value , $separator , $limit ] ) ;
}

