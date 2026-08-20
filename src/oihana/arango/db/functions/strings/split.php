<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Split a string into an array using a separator.
 *
 * This helper wraps the ArangoDB AQL function `SPLIT(value, separator, limit)`
 * which splits the given string into an array of strings using the specified
 * separator. The limit is optional, and omitting it splits the whole value.
 *
 * Example AQL usage:
 * ```aql
 * SPLIT("a,b,c", ",")           // returns ["a", "b", "c"]
 * SPLIT("hello world", " ")     // returns ["hello", "world"]
 * SPLIT("hello", "")            // returns ["h", "e", "l", "l", "o"] (split by character)
 * ```
 *
 * ⚠ **The limit truncates, it does not merge.** AQL keeps the first `limit`
 * parts and **discards the rest**, where PHP's own `explode()` merges the
 * remainder into the last element. The two read alike and answer differently:
 * ```aql
 * SPLIT("a,b,c,d", ",", 3)      // returns ["a", "b", "c"]   — "d" is gone
 * SPLIT("a,b,c,d", ",", 1)      // returns ["a"]
 * SPLIT("a,b,c,d", ",", 0)      // returns []                — keep nothing
 * ```
 * ```php
 * explode(",", "a,b,c,d", 3);   // returns ["a", "b", "c,d"] — "d" is kept
 * explode(",", "a,b,c,d", 0);   // returns ["a,b,c,d"]       — 0 is read as 1
 * ```
 * A limit of `0` is therefore an empty answer in AQL and a whole one in PHP.
 * Pass `null` — not `0` — to mean "no limit".
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\split;
 *
 * split( 'doc.text' , '","' );            // SPLIT(doc.text,",")
 * split( 'doc.text' , '","' , 2 );        // SPLIT(doc.text,",",2)
 * split( 'doc.text' , 'doc.separator' );  // SPLIT(doc.text,doc.separator)
 * ```
 *
 * @param string   $value     String expression to split.
 * @param string   $separator Separator string to split on, quoted by the caller when it is text.
 * @param int|null $limit     Optional number of parts to keep. `null` omits the argument and splits the whole value.
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

