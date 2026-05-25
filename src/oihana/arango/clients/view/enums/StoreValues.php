<?php

namespace oihana\arango\clients\view\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Per-link / per-field `storeValues` strategy on an ArangoSearch view.
 *
 * Controls whether the view keeps the indexed values alongside the
 * inverted index, so that AQL `SEARCH` queries can return the value
 * without touching the source document.
 *
 * @see https://docs.arangodb.com/stable/index-and-search/arangosearch/arangosearch-views-reference/#link-properties
 *
 * @package oihana\arango\clients\view\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class StoreValues
{
    use ConstantsTrait ;

    /**
     * The view also stores the source document's `_id` next to each
     * index entry. Slightly heavier on disk; lets `SEARCH` covering
     * queries avoid the document round-trip.
     */
    public const string ID = 'id' ;

    /**
     * The view stores only the index entries themselves — no extra
     * payload. Default behaviour.
     */
    public const string NONE = 'none' ;
}
