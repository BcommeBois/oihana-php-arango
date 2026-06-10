<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Match documents with an approximate Jaccard similarity of at least a threshold.
 *
 * Wraps the ArangoDB AQL function `MINHASH_MATCH(path, target, threshold, analyzer)`.
 * The similarity is approximated with the given `minhash` Analyzer — an
 * efficient first pass for entity resolution (duplicate detection) before an
 * exact `JACCARD()` computation.
 *
 * **Argument order notice** — in AQL the optional `threshold` sits *before* the
 * mandatory `analyzer`; PHP forbids a required parameter after an optional one,
 * so this helper takes the analyzer **third** and the optional threshold
 * **last**, then re-orders the emitted AQL arguments.
 *
 * Example AQL usage:
 * ```aql
 * MINHASH_MATCH(doc.text, "the quick brown fox", 0.5, "myMinHash")
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\minhashMatch;
 *
 * echo minhashMatch( 'doc.text' , 'the quick brown fox' , 'myMinHash' , 0.5 ) ;
 * // 'MINHASH_MATCH(doc.text,"the quick brown fox",0.5,"myMinHash")'
 *
 * echo minhashMatch( 'doc.text' , 'the quick brown fox' , 'myMinHash' ) ;
 * // 'MINHASH_MATCH(doc.text,"the quick brown fox","myMinHash")'
 * ```
 *
 * @param string     $path      Attribute path expression to test (kept raw).
 * @param string     $target    String to hash and compare against (emitted as a quoted string literal).
 * @param string     $analyzer  Name of the `minhash` Analyzer (emitted as a quoted string literal).
 * @param float|null $threshold Optional similarity threshold in `[0.0, 1.0]`.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#minhash_match
 * @see ngramMatch()
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function minhashMatch( string $path , string $target , string $analyzer , ?float $threshold = null ) : string
{
    $args = [ $path , json_encode( $target ) ] ;

    if ( $threshold !== null )
    {
        $args[] = $threshold ;
    }

    $args[] = json_encode( $analyzer ) ;

    return func( SearchFunction::MINHASH_MATCH , $args ) ;
}
