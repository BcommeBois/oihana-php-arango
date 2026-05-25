<?php

namespace oihana\arango\clients\analyzer\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * ArangoSearch analyzer type discriminator, used as the `type` field
 * of every payload sent to `POST /_api/analyzer` and returned by
 * `GET /_api/analyzer/{name}`.
 *
 * Only the V1 must-have set is exposed today — the additional types
 * shipped by arangojs (`ngram`, `pipeline`, `aql`, `classification`,
 * `nearest_neighbors`, `geo_json`, `geo_point`, `geo_s2`,
 * `segmentation`, `collation`, `minhash`, `delimiter`,
 * `multi_delimiter`, `stopwords`) are deferred to a V2 follow-up.
 *
 * @see https://docs.arangodb.com/stable/index-and-search/analyzers/#analyzer-types
 *
 * @package oihana\arango\clients\analyzer\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class AnalyzerType
{
    use ConstantsTrait ;

    /**
     * Pass-through analyzer — emits its input verbatim, with no
     * transformation. Useful as the default analyzer on every link
     * and on every field that does not need language-aware
     * normalisation.
     */
    public const string IDENTITY = 'identity' ;

    /**
     * Locale-aware normaliser. Lower-cases / upper-cases the input
     * and optionally strips diacritics. Does not tokenise — use
     * {@see self::TEXT} for that.
     */
    public const string NORM = 'norm' ;

    /**
     * Locale-aware stemmer. Reduces inflected forms of a word to a
     * common root (e.g. `running` → `run`). Single-token input only.
     */
    public const string STEM = 'stem' ;

    /**
     * Full-text analyzer — tokenises on word boundaries, optionally
     * lower-cases, removes stopwords, applies stemming and accent
     * folding, and optionally emits edge n-grams for prefix search.
     */
    public const string TEXT = 'text' ;
}
