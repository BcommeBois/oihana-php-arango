<?php

namespace oihana\arango\controllers\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The HTTP-side **values** of the facet surface — the words a client may write
 * in a query parameter, as opposed to the parameter *names*.
 *
 * Unlike {@see GroupParam}, which carries the short keys of the `?group={...}`
 * JSON spec, the facet parameters take plain scalars, so the only vocabulary
 * they need is this one. The parameter names themselves
 * ({@see \oihana\arango\enums\Arango::FACET_COUNTS},
 * {@see \oihana\arango\enums\Arango::FACET_COUNTS_LIMIT},
 * {@see \oihana\arango\enums\Arango::FACETS_ONLY}) live on `Arango` alongside
 * every other query-parameter name, and stay there: they are published
 * constants consuming projects already reference.
 *
 * The words declared here never reach the model: the controller translates them
 * into the model's own contract, the way
 * {@see \oihana\arango\controllers\traits\PrepareGroupTrait::prepareGroup()}
 * maps `GroupParam` onto {@see \oihana\arango\models\enums\Group}. That is what
 * keeps the model free of any HTTP vocabulary.
 *
 * @package oihana\arango\controllers\enums
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
class FacetParam
{
    use ConstantsTrait ;

    /**
     * The `?facetCountsLimit=all` keyword: return **every** bucket, overriding a
     * `Facet::LIMIT` declared on the facet.
     *
     * A word rather than a number, and deliberately so: `0` would read as "no
     * bucket" to whoever writes it and compile to "every bucket" — the ambiguity
     * {@see \oihana\arango\models\traits\queries\FacetCountsQueryTrait} refuses.
     * One rule holds everywhere: a limit is a positive integer, and "all of
     * them" is said with this word.
     *
     * Translated by
     * {@see \oihana\arango\controllers\traits\PrepareFacetCountsLimitTrait::prepareFacetCountsLimit()}
     * into the model-level `false` — "explicitly unlimited", which the model
     * tells apart from an absent parameter ("use the declaration").
     */
    public const string ALL = 'all' ;
}
