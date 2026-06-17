<?php

namespace oihana\arango\clients\view\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * ArangoDB view type discriminator, used as the `type` field of every
 * payload sent to `POST /_api/view` and returned by
 * `GET /_api/view/{name}` and `GET /_api/view/{name}/properties`.
 *
 * Two types are supported:
 * - `arangosearch` — the view owns its inverted index, configured through
 *   per-collection `links`,
 * - `search-alias` — the view is a thin alias over one or more `inverted`
 *   indexes declared on the collections themselves (shareable, independent
 *   lifecycle, federatable across collections).
 *
 * @see https://docs.arangodb.com/stable/index-and-search/arangosearch/
 *
 * @package oihana\arango\clients\view\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ViewType
{
    use ConstantsTrait ;

    /**
     * ArangoSearch view — full-text indexing on top of one or more
     * collections, configured through `links` mapping each indexed
     * collection to per-field analyzer chains.
     */
    public const string ARANGOSEARCH = 'arangosearch' ;

    /**
     * Search-alias view — aggregates one `inverted` index per collection
     * (declared on the collections), referenced through an `indexes` list
     * of `{collection, index}` entries. The natural substrate for a
     * federated, multi-collection search.
     */
    public const string SEARCH_ALIAS = 'search-alias' ;
}
