<?php

namespace oihana\arango\clients\analyzer\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * ArangoSearch analyzer type discriminator, used as the `type` field
 * of every payload sent to `POST /_api/analyzer` and returned by
 * `GET /_api/analyzer/{name}`.
 *
 * The exposed set covers the V1 must-have analyzers plus `ngram`
 * (substring / autocomplete indexing) and `pipeline` (an ordered chain
 * of sub-analyzers — typically `norm` → `ngram` for case- and
 * accent-insensitive autocomplete). The remaining types shipped by
 * arangojs (`aql`, `classification`, `nearest_neighbors`, `geo_json`,
 * `geo_point`, `geo_s2`, `segmentation`, `collation`, `minhash`,
 * `delimiter`, `multi_delimiter`, `stopwords`) are deferred to a later
 * follow-up.
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
     * N-gram analyzer — emits the substrings (n-grams) of its input
     * between a `min` and `max` length. The building block of
     * substring / "as-you-type" autocomplete search, typically paired
     * with a `text` analyzer on the same field (see
     * {@see \oihana\arango\clients\analyzer\NgramAnalyzer}).
     */
    public const string NGRAM = 'ngram' ;

    /**
     * Locale-aware normaliser. Lower-cases / upper-cases the input
     * and optionally strips diacritics. Does not tokenise — use
     * {@see self::TEXT} for that.
     */
    public const string NORM = 'norm' ;

    /**
     * Pipeline analyzer — runs an ordered chain of sub-analyzers, each
     * fed the output of the previous one. The typed way to compose
     * analyzers the server otherwise only exposes individually — most
     * notably `norm` → `ngram`, which normalises case and accents
     * **before** the n-gram split so case-/accent-insensitive
     * autocomplete actually matches (a standalone `ngram` normalises
     * neither). See
     * {@see \oihana\arango\clients\analyzer\PipelineAnalyzer}.
     */
    public const string PIPELINE = 'pipeline' ;

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
