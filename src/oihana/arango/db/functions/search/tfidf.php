<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Score documents with the term frequency–inverse document frequency algorithm (TF-IDF).
 *
 * Wraps the ArangoDB AQL scoring function `TFIDF(doc, normalize)`. The first
 * argument must be the document variable emitted by a `FOR … IN viewName`
 * operation, and the function can only be used together with a `SEARCH`. Sort
 * **descending** by the score to get the most relevant documents first.
 *
 * Example AQL usage:
 * ```aql
 * FOR doc IN viewName
 *   SEARCH ...
 *   SORT TFIDF(doc) DESC
 *   RETURN doc
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\tfidf;
 *
 * echo tfidf( 'doc' ) ;        // 'TFIDF(doc)'
 * echo tfidf( 'doc' , true ) ; // 'TFIDF(doc,true)'
 * ```
 *
 * @param string    $doc       The document variable emitted by `FOR … IN viewName` (kept raw).
 * @param bool|null $normalize Optional — whether to normalize the score (server default `false`).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#tfidf
 * @see bm25()
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function tfidf( string $doc , ?bool $normalize = null ) : string
{
    $args = [ $doc ] ;

    if ( $normalize !== null )
    {
        $args[] = json_encode( $normalize ) ;
    }

    return func( SearchFunction::TFIDF , $args ) ;
}
