<?php

namespace oihana\arango\clients\analyzer\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * ArangoSearch analyzer features, used as entries of the `features`
 * array on `POST /_api/analyzer` (and as the per-field `features`
 * array on inverted indexes / arangosearch views).
 *
 * Features determine which metadata the indexer keeps alongside each
 * indexed token — and therefore which AQL `SEARCH` operators are
 * available against the analyzed field:
 *
 * - {@see self::FREQUENCY} is required by `BM25()` / `TFIDF()` scoring,
 * - {@see self::NORM} is required by `BM25()` length normalisation,
 * - {@see self::POSITION} is required by `PHRASE()`,
 * - {@see self::OFFSET} is required by snippet highlighting and is
 *   only meaningful when {@see self::POSITION} is also enabled.
 *
 * @see https://docs.arangodb.com/stable/index-and-search/analyzers/#analyzer-features
 *
 * @package oihana\arango\clients\analyzer\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class AnalyzerFeature
{
    use ConstantsTrait ;

    /**
     * Keeps the token frequency per field. Required by `BM25()` and
     * `TFIDF()` scoring functions.
     */
    public const string FREQUENCY = 'frequency' ;

    /**
     * Keeps the document length norm per field. Required by `BM25()`
     * length normalisation.
     */
    public const string NORM = 'norm' ;

    /**
     * Keeps the byte offset of each token within the source field.
     * Required by highlighting / snippet extraction. Implies
     * {@see self::POSITION}.
     */
    public const string OFFSET = 'offset' ;

    /**
     * Keeps the ordinal position of each token within the source
     * field. Required by `PHRASE()`.
     */
    public const string POSITION = 'position' ;
}
