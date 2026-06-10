<?php

namespace oihana\arango\models\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The keys of the model-level ArangoSearch declaration (`AQL::VIEW`) and the
 * synthetic relevance sort key.
 *
 * A `Documents` model declares an ArangoSearch View through the `AQL::VIEW`
 * block; when present, the `?search=` parameter switches from the simple
 * `LIKE` sweep to an index-accelerated, relevance-ranked `SEARCH` against
 * the View:
 *
 * ```php
 * AQL::VIEW =>
 * [
 *     Search::NAME     => 'placesView' ,             // the View name (required)
 *     Search::ANALYZER => 'text_fr' ,                // Analyzer of the searched fields
 *     Search::FIELDS   => [ 'name' => 3 , 'description' => 1 ] , // field => boost
 *     Search::PHRASE   => true ,                     // exact-phrase bonus (boost ×2)
 *     Search::FUZZY    => 1 ,                        // Levenshtein tolerance (0 = off)
 * ]
 * ```
 *
 * @see \oihana\arango\models\traits\aql\SearchTrait
 */
class Search
{
    use ConstantsTrait ;

    /**
     * The Analyzer applied to the searched fields (indexing and querying side).
     * Defaults to `identity` — declare a text Analyzer (`text_fr`, `text_en`, …)
     * for linguistic matching.
     */
    public const string ANALYZER = 'analyzer' ;

    /**
     * A per-field boost weight (also accepted as the plain numeric value of a
     * `FIELDS` entry). Defaults to `1`.
     */
    public const string BOOST = 'boost' ;

    /**
     * The searched fields: `field => boost` (or `field => [ Search::BOOST => n ]`).
     * Falls back to the model's `AQL::SEARCHABLE` list (boost `1`) when omitted.
     */
    public const string FIELDS = 'fields' ;

    /**
     * Levenshtein tolerance: the maximum edit distance for fuzzy term matching
     * (`LEVENSHTEIN_MATCH`). `0` (default) disables fuzzy matching.
     */
    public const string FUZZY = 'fuzzy' ;

    /**
     * The name of the ArangoSearch View (required to activate the View search).
     */
    public const string NAME = 'name' ;

    /**
     * Whether to add an exact-phrase bonus: when `true`, a `PHRASE()` match on
     * a field weighs twice the field boost, ranking exact phrases first.
     */
    public const string PHRASE = 'phrase' ;

    /**
     * The synthetic relevance sort key exposed to `?sort=` when a View search
     * is active (`?sort=-score`, `?sort=score,name`, …) — the counterpart of
     * the `distance` key driven by `?near=`. Resolves to `BM25(doc)`.
     */
    public const string SCORE = 'score' ;
}
