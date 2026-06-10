<?php

namespace oihana\arango\db\operations;

use InvalidArgumentException;
use oihana\exceptions\BindException;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\SearchScorer;
use oihana\enums\Char;
use oihana\enums\Order;

use function oihana\arango\db\functions\search\bm25;
use function oihana\arango\db\functions\search\tfidf;
use function oihana\core\strings\compile;

/**
 * Builds a complete, relevance-ranked AQL search query over an ArangoSearch View.
 *
 * The generated query follows the canonical scored-search form:
 * ```
 * FOR <docRef> IN <view>
 *   SEARCH <expression> [OPTIONS { … }]
 *   LET <scoreRef> = BM25(<docRef>) | TFIDF(<docRef>)
 *   SORT <scoreRef> DESC
 *   LIMIT [<offset>,] <limit>
 *   RETURN <return>
 * ```
 *
 * Both scorers rank better matches with **higher** values, so the sort is
 * always descending — there is no direction to get wrong. The score is bound
 * to a `LET` variable (`$scoreRef`, default `score`) so a custom `$return`
 * expression can expose it, e.g. `'MERGE(doc, { score: score })'`.
 *
 * The `SEARCH` segment reuses {@see aqlFor()} / {@see aqlSearch()}: the
 * optional `$analyzer` wraps the expression in `ANALYZER(expr, "name")` and
 * the optional `$options` becomes the `SEARCH … OPTIONS { … }` object
 * (hydrated into {@see \oihana\arango\db\options\SearchOptions}).
 *
 * > The scorer functions require the indexed fields' Analyzers to have the
 * > `"frequency"` feature enabled (and `"norm"` for meaningful BM25 length
 * > normalization), otherwise the score is `0`.
 *
 * ### Example: phrase search ranked by BM25
 * ```php
 * use function oihana\arango\db\functions\search\phrase;
 * use function oihana\arango\db\operations\aqlScoredSearch;
 *
 * $aql = aqlScoredSearch
 * (
 *     view     : 'placesView' ,
 *     search   : phrase( 'doc.name' , 'scierie' ) ,
 *     limit    : 20 ,
 *     analyzer : 'text_fr' ,
 * ) ;
 * // FOR doc IN placesView SEARCH ANALYZER(PHRASE(doc.name,"scierie"),"text_fr")
 * //   LET score = BM25(doc) SORT score DESC LIMIT 20 RETURN doc
 * ```
 *
 * ### Example: TF-IDF, pagination, and the score in the output
 * ```php
 * $aql = aqlScoredSearch
 * (
 *     view      : 'articlesView' ,
 *     search    : 'doc.text IN TOKENS(@q, "text_en")' ,
 *     limit     : 10 ,
 *     offset    : 20 ,
 *     scorer    : SearchScorer::TFIDF ,
 *     normalize : true ,
 *     return    : 'MERGE(doc, { score: score })' ,
 * ) ;
 * // FOR doc IN articlesView SEARCH doc.text IN TOKENS(@q, "text_en")
 * //   LET score = TFIDF(doc,true) SORT score DESC LIMIT 20, 10 RETURN MERGE(doc, { score: score })
 * ```
 *
 * @param string $view                      The ArangoSearch View to query.
 * @param string|array $search              The `SEARCH` expression (kept raw; arrays are compiled like `AQL::SEARCH`).
 * @param int $limit                        The maximum number of matches to return (the `LIMIT`).
 * @param string|null $analyzer             Optional Analyzer name wrapping the expression in `ANALYZER(expr, "name")`.
 * @param array|object|string|null $options Optional `SEARCH … OPTIONS { … }` object (see {@see aqlSearch()}).
 * @param string $scorer                    The scoring algorithm: `SearchScorer::BM25` (default) or `SearchScorer::TFIDF`.
 * @param float|null $k                     Optional BM25 term-frequency calibration (BM25 only).
 * @param float|null $b                     Optional BM25 text-length scaling (BM25 only).
 * @param bool|null $normalize              Optional TF-IDF score normalization (TFIDF only).
 * @param int $offset                       Optional number of matches to skip (pagination).
 * @param string $docRef                    The iteration variable name (default `'doc'`).
 * @param string $scoreRef                  The `LET` score variable name (default `'score'`).
 * @param string|null $return               Optional `RETURN` expression. Defaults to the iteration variable.
 *
 * @return string The complete AQL scored-search query.
 *
 * @throws BindException
 * @throws ReflectionException If the search options hydration fails.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#scoring-functions
 * @see SearchScorer
 * @see aqlSearch()
 * @see bm25()
 * @see tfidf()
 *
 * @package oihana\arango\db\operations
 * @since   1.2.0
 * @author  Marc Alcaraz
 */
function aqlScoredSearch
(
    string                   $view ,
    string|array             $search ,
    int                      $limit ,
    ?string                  $analyzer  = null ,
    array|object|string|null $options   = null ,
    string                   $scorer    = SearchScorer::BM25 ,
    ?float                   $k         = null ,
    ?float                   $b         = null ,
    ?bool                    $normalize = null ,
    int                      $offset    = 0 ,
    string                   $docRef    = 'doc' ,
    string                   $scoreRef  = 'score' ,
    ?string                  $return    = null ,
)
: string
{
    $score = match ( $scorer )
    {
        SearchScorer::BM25 => ( $normalize === null ) ? bm25( $docRef , $k , $b ) : throw new InvalidArgumentException
        (
            "aqlScoredSearch(): the 'normalize' argument only applies to the 'tfidf' scorer."
        ) ,
        SearchScorer::TFIDF => ( $k === null && $b === null ) ? tfidf( $docRef , $normalize ) : throw new InvalidArgumentException
        (
            "aqlScoredSearch(): the 'k' and 'b' arguments only apply to the 'bm25' scorer."
        ) ,
        default => throw new InvalidArgumentException
        (
            "aqlScoredSearch(): unsupported scorer '$scorer', expected 'bm25' or 'tfidf'."
        ) ,
    } ;

    return compile
    ([
        aqlFor
        ([
            AQL::DOC_REF        => $docRef ,
            AQL::IN             => $view ,
            AQL::SEARCH         => $search ,
            AQL::ANALYZER       => $analyzer ,
            AQL::SEARCH_OPTIONS => $options ,
        ]) ,
        aqlLet   ( $scoreRef , $score ) ,
        aqlSort  ( $scoreRef . Char::SPACE . Order::DESC ) ,
        aqlLimit ( $limit , $offset ) ,
        aqlReturn( $return ?? $docRef ) ,
    ]) ;
}
