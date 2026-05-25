<?php

namespace oihana\arango\clients\collection\indexes\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Type values accepted by the ArangoDB index API as the `type` field
 * of a `POST /_api/index` request (and returned on the response side).
 *
 * Notes:
 * - `primary` and `edge` are server-managed and cannot be created
 *   through `POST /_api/index` — they are listed here for completeness
 *   (the {@see \oihana\arango\clients\collection\Collection::indexes()}
 *   listing exposes them as-is).
 * - `hash` and `skiplist` are aliases of `persistent` since ArangoDB
 *   3.7 and are intentionally NOT exposed — use {@see PERSISTENT}.
 * - `fulltext` has been deprecated since ArangoDB 3.10 in favour of
 *   {@see INVERTED} / ArangoSearch views.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/indexes/
 *
 * @package oihana\arango\clients\collection\indexes\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class IndexType
{
    use ConstantsTrait ;

    /**
     * Built-in edge index automatically created on every edge collection.
     * Cannot be created manually; listed for completeness.
     */
    public const string EDGE = 'edge' ;

    /**
     * Full-text inverted index. Deprecated since ArangoDB 3.10 in
     * favour of {@see INVERTED} / ArangoSearch views.
     */
    public const string FULLTEXT = 'fulltext' ;

    /**
     * Geospatial index (point or polygon-aware).
     */
    public const string GEO = 'geo' ;

    /**
     * Inverted index (ArangoSearch-backed). Replacement for `fulltext`.
     */
    public const string INVERTED = 'inverted' ;

    /**
     * Multi-dimensional index (stable since ArangoDB 3.12).
     */
    public const string MDI = 'mdi' ;

    /**
     * Multi-dimensional index with explicit prefix fields (ArangoDB 3.12+).
     * Returned automatically by {@see \oihana\arango\clients\collection\indexes\MDIIndex::toArray()}
     * when `prefixFields` is provided.
     */
    public const string MDI_PREFIXED = 'mdi-prefixed' ;

    /**
     * Persistent (B-tree) index — the default for most use cases.
     * Replaces the legacy `hash` and `skiplist` types since ArangoDB 3.7.
     */
    public const string PERSISTENT = 'persistent' ;

    /**
     * Built-in primary index automatically created on every collection
     * (`_key` lookup). Cannot be created manually; listed for completeness.
     */
    public const string PRIMARY = 'primary' ;

    /**
     * Time-to-live index that removes documents past their `expireAfter`
     * threshold.
     */
    public const string TTL = 'ttl' ;

    /**
     * Vector index (ArangoDB 3.13+, Faiss-backed). Used for similarity
     * search over embedding vectors.
     */
    public const string VECTOR = 'vector' ;
}
