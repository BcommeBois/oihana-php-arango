<?php

namespace oihana\arango\clients\view\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * ArangoDB view type discriminator, used as the `type` field of every
 * payload sent to `POST /_api/view` and returned by
 * `GET /_api/view/{name}` and `GET /_api/view/{name}/properties`.
 *
 * Only the V1 must-have type is exposed today — the `search-alias`
 * type shipped by arangojs is deferred to a V2 follow-up.
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
}
