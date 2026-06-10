<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Score documents with the Best Matching 25 algorithm (Okapi BM25).
 *
 * Wraps the ArangoDB AQL scoring function `BM25(doc, k, b)`. The first argument
 * must be the document variable emitted by a `FOR … IN viewName` operation, and
 * the function can only be used together with a `SEARCH`. Sort **descending**
 * by the score to get the most relevant documents first.
 *
 * AQL arguments are positional: when `$b` is provided without `$k`, the helper
 * fills `$k` with the official server default (`1.2`). The Analyzers used for
 * indexing must have the `"frequency"` feature enabled (and `"norm"` for
 * meaningful length normalization), otherwise the score is `0`.
 *
 * Example AQL usage:
 * ```aql
 * FOR doc IN viewName
 *   SEARCH ...
 *   SORT BM25(doc) DESC
 *   RETURN doc
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\bm25;
 *
 * echo bm25( 'doc' ) ;              // 'BM25(doc)'
 * echo bm25( 'doc' , 2.4 , 1.0 ) ;  // 'BM25(doc,2.4,1)'
 * echo bm25( 'doc' , b: 0.5 ) ;     // 'BM25(doc,1.2,0.5)'
 * ```
 *
 * @param string     $doc The document variable emitted by `FOR … IN viewName` (kept raw).
 * @param float|null $k   Optional term-frequency calibration, `>= 0.0` (server default `1.2`; `0` = binary model).
 * @param float|null $b   Optional text-length scaling in `[0.0, 1.0]` (server default `0.75`; `1` = BM11, `0` = BM15).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#bm25
 * @see tfidf()
 * @see boost()
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function bm25( string $doc , ?float $k = null , ?float $b = null ) : string
{
    $args = [ $doc ] ;

    if ( $b !== null )
    {
        $args[] = $k ?? 1.2 ;
        $args[] = $b ;
    }
    elseif ( $k !== null )
    {
        $args[] = $k ;
    }

    return func( SearchFunction::BM25 , $args ) ;
}
