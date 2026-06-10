<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Match documents whose attribute has an n-gram similarity above a threshold.
 *
 * Wraps the ArangoDB AQL function `NGRAM_MATCH(path, target, threshold, analyzer)`.
 * The n-grams of both the attribute and the target are produced by the given
 * Analyzer (use an `ngram` Analyzer with `preserveOriginal: false` and `min`
 * equal to `max`, with the `"position"` and `"frequency"` features enabled).
 *
 * **Argument order notice** — in AQL the optional `threshold` sits *before* the
 * mandatory `analyzer`; PHP forbids a required parameter after an optional one,
 * so this helper takes the analyzer **third** and the optional threshold
 * **last**, then re-orders the emitted AQL arguments. When `$threshold` is
 * `null` the three-argument AQL form is emitted (server default: `0.7`).
 *
 * Example AQL usage:
 * ```aql
 * NGRAM_MATCH(doc.text, "quick fox", "bigram")           // threshold defaults to 0.7
 * NGRAM_MATCH(doc.text, "quick blue fox", 0.4, "bigram")
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\ngramMatch;
 *
 * echo ngramMatch( 'doc.text' , 'quick fox' , 'bigram' ) ;
 * // 'NGRAM_MATCH(doc.text,"quick fox","bigram")'
 *
 * echo ngramMatch( 'doc.text' , 'quick blue fox' , 'bigram' , 0.4 ) ;
 * // 'NGRAM_MATCH(doc.text,"quick blue fox",0.4,"bigram")'
 * ```
 *
 * @param string     $path      Attribute path expression to test (kept raw).
 * @param string     $target    String to compare against (emitted as a quoted string literal).
 * @param string     $analyzer  Name of the `ngram` Analyzer (emitted as a quoted string literal).
 * @param float|null $threshold Optional similarity threshold in `[0.0, 1.0]` (server default `0.7`).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#ngram_match
 * @see minhashMatch()
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function ngramMatch( string $path , string $target , string $analyzer , ?float $threshold = null ) : string
{
    $args = [ $path , json_encode( $target ) ] ;

    if ( $threshold !== null )
    {
        $args[] = $threshold ;
    }

    $args[] = json_encode( $analyzer ) ;

    return func( SearchFunction::NGRAM_MATCH , $args ) ;
}
