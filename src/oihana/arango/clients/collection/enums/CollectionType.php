<?php

namespace oihana\arango\clients\collection\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Collection type values defined by the ArangoDB server protocol.
 *
 * Used as the `type` field of `POST /_api/collection` (to choose
 * between a document collection and an edge collection) and in the
 * response of `GET /_api/collection/{name}/properties`.
 *
 * The numeric values match the canonical server constants:
 * - `2` → document collection (default),
 * - `3` → edge collection.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/collections/
 *
 * @package oihana\arango\clients\collection\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class CollectionType
{
    use ConstantsTrait ;

    /**
     * Document collection (default).
     */
    public const int DOCUMENT = 2 ;

    /**
     * Edge collection.
     */
    public const int EDGE = 3 ;
}
