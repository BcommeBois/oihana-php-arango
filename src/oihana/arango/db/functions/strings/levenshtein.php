<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Calculate the Damerau-Levenshtein distance between two strings.
 *
 * This helper wraps the ArangoDB AQL function `LEVENSHTEIN_DISTANCE(value1, value2)`
 * which calculates the Damerau-Levenshtein distance between two strings. This
 * distance represents the minimum number of operations (insertions, deletions,
 * substitutions, and transpositions) needed to transform one string into another.
 *
 * Example AQL usage:
 * ```aql
 * LEVENSHTEIN_DISTANCE( "kitten" , "sitting" ) // returns 3
 * LEVENSHTEIN_DISTANCE( "hello"  , "hello"   ) // returns 0 (identical)
 * LEVENSHTEIN_DISTANCE( ""       , "hello"   ) // returns 5 (all insertions)
 * LEVENSHTEIN_DISTANCE( "abc"    , "bac"     ) // returns 1 (one transposition)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\levenshtein;
 *
 * $expr = levenshtein('doc.name1', 'doc.name2');
 * // Produces: 'LEVENSHTEIN_DISTANCE(doc.name1, doc.name2)'
 * ```
 *
 * @param string $value1 First string expression.
 * @param string $value2 Second string expression.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#levenshtein_distance
 * @see https://en.wikipedia.org/wiki/Damerau%E2%80%93Levenshtein_distance
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function levenshtein( string $value1 , string $value2 ): string
{
    return func(StringFunction::LEVENSHTEIN_DISTANCE , [ $value1 , $value2 ] ) ;
}

