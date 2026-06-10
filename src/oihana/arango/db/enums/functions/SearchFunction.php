<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

/**
 * ArangoSearch-specific AQL functions: context (analyzer/boost), filtering,
 * scoring and search highlighting. These functions are meant to be used inside
 * a `SEARCH` operation against a View (or a `FILTER` backed by an inverted index).
 *
 * Functions that also exist outside of `SEARCH` keep their original enum:
 * `MIN_MATCH` and `IN_RANGE` live in {@see MiscFunction}, `STARTS_WITH`,
 * `TOKENS` and `LIKE` in {@see StringFunction}.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/
 */
class SearchFunction
{
    use FunctionCallTrait ;

    /**
     * Set the Analyzer for the wrapped search expression (and its nested functions).
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#analyzer
     */
    public const string ANALYZER = 'ANALYZER' ;

    /**
     * Score documents with the Best Matching 25 algorithm (Okapi BM25).
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#bm25
     */
    public const string BM25 = 'BM25' ;

    /**
     * Override the boost value of the wrapped search expression for scorer functions.
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#boost
     */
    public const string BOOST = 'BOOST' ;

    /**
     * Match documents where an attribute is present (optionally of a given type,
     * indexed by a given Analyzer, or indexed as a nested field).
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#exists
     */
    public const string EXISTS = 'EXISTS' ;

    /**
     * Match documents within a (Damerau-)Levenshtein distance of a target string.
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#levenshtein_match
     */
    public const string LEVENSHTEIN_MATCH = 'LEVENSHTEIN_MATCH' ;

    /**
     * Match documents with an approximate Jaccard similarity (MinHash Analyzer).
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#minhash_match
     */
    public const string MINHASH_MATCH = 'MINHASH_MATCH' ;

    /**
     * Match documents whose attribute has an n-gram similarity above a threshold.
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#ngram_match
     */
    public const string NGRAM_MATCH = 'NGRAM_MATCH' ;

    /**
     * Return match offsets for search highlighting (requires the `offset` Analyzer feature).
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#offset_info
     */
    public const string OFFSET_INFO = 'OFFSET_INFO' ;

    /**
     * Match documents containing a phrase (tokens in the given order, with optional wildcards).
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#phrase
     */
    public const string PHRASE = 'PHRASE' ;

    /**
     * Score documents with the term frequency–inverse document frequency algorithm (TF-IDF).
     * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#tfidf
     */
    public const string TFIDF = 'TFIDF' ;
}
