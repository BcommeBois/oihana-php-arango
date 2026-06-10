<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The ArangoSearch scoring algorithms, used to select the scorer function in
 * {@see \oihana\arango\db\operations\aqlScoredSearch()}. Both scorers rank
 * better matches with **higher** values, so relevance sorting is always
 * descending.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#scoring-functions
 */
class SearchScorer
{
    use ConstantsTrait ;

    /**
     * Best Matching 25 (Okapi BM25) — `BM25(doc, k, b)`. The recommended
     * general-purpose scorer; requires the `"frequency"` Analyzer feature
     * (and `"norm"` for meaningful length normalization).
     */
    public const string BM25 = 'bm25' ;

    /**
     * Term frequency–inverse document frequency — `TFIDF(doc, normalize)`.
     */
    public const string TFIDF = 'tfidf' ;
}
