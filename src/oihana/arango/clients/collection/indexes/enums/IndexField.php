<?php

namespace oihana\arango\clients\collection\indexes\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * JSON field names exchanged with the ArangoDB index API
 * (`/_api/index`), on both the request side (body of
 * `POST /_api/index`) and the response side (entries of
 * `GET /_api/index?collection=…` and `GET /_api/index/{id}`).
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/indexes/
 *
 * @package oihana\arango\clients\collection\indexes\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class IndexField
{
    use ConstantsTrait ;

    /**
     * Analyzer name applied at the top level of an inverted index
     * (`inverted` only).
     */
    public const string ANALYZER = 'analyzer' ;

    /**
     * Whether inverted-index entries are cached in memory
     * (`inverted` only).
     */
    public const string CACHE = 'cache' ;

    /**
     * Whether the persistent index should keep an in-memory cache of
     * frequently accessed entries (`persistent` only).
     */
    public const string CACHE_ENABLED = 'cacheEnabled' ;

    /**
     * Frequency at which obsolete segments are cleaned up
     * (`inverted` only).
     */
    public const string CLEANUP_INTERVAL_STEP = 'cleanupIntervalStep' ;

    /**
     * Commit interval, in milliseconds, between two index updates
     * (`inverted` only).
     */
    public const string COMMIT_INTERVAL_MSEC = 'commitIntervalMsec' ;

    /**
     * Consolidation interval, in milliseconds, between two segment
     * merges (`inverted` only).
     */
    public const string CONSOLIDATION_INTERVAL_MSEC = 'consolidationIntervalMsec' ;

    /**
     * Whether duplicate documents matching a unique index should be
     * silently deduplicated rather than rejected (`persistent` only).
     */
    public const string DEDUPLICATE = 'deduplicate' ;

    /**
     * Whether the index should maintain selectivity estimates for the
     * query optimizer.
     */
    public const string ESTIMATES = 'estimates' ;

    /**
     * Document lifetime (in seconds) past which documents are deleted
     * by the TTL background task. Required for `ttl` indexes.
     */
    public const string EXPIRE_AFTER = 'expireAfter' ;

    /**
     * Per-field features kept by an inverted index — typically a subset
     * of `frequency`, `position`, `offset`, `norm` (`inverted` only).
     */
    public const string FEATURES = 'features' ;

    /**
     * List of document attribute paths the index applies to.
     */
    public const string FIELDS = 'fields' ;

    /**
     * Numeric type stored for each indexed value (`mdi` / `mdi-prefixed`
     * only). Currently the only accepted value is `"double"`.
     */
    public const string FIELD_VALUE_TYPES = 'fieldValueTypes' ;

    /**
     * Whether the geo index input is expressed as GeoJSON
     * (`{ type: 'Point', coordinates: [lng, lat] }`) rather than as a
     * `[latitude, longitude]` pair (`geo` only).
     */
    public const string GEO_JSON = 'geoJson' ;

    /**
     * Server-side index handle. Present on every response entry.
     */
    public const string ID = 'id' ;

    /**
     * Whether the inverted index covers every attribute of the
     * documents, regardless of `fields` (`inverted` only).
     */
    public const string INCLUDE_ALL_FIELDS = 'includeAllFields' ;

    /**
     * Whether to build the index in the background, without blocking
     * concurrent writes to the collection.
     */
    public const string IN_BACKGROUND = 'inBackground' ;

    /**
     * Wrapper field carrying the listing of indexes in the response of
     * `GET /_api/index?collection=…`.
     */
    public const string INDEXES = 'indexes' ;

    /**
     * Minimum word length to index, in characters (`fulltext` only).
     */
    public const string MIN_LENGTH = 'minLength' ;

    /**
     * Optional human-readable index name. Used by
     * {@see \oihana\arango\clients\collection\Collection::dropIndex()}
     * as an alternative to the numeric `id`.
     */
    public const string NAME = 'name' ;

    /**
     * Parallelism level — number of threads that may build / query the
     * index in parallel (`inverted` / `vector` only).
     */
    public const string PARALLELISM = 'parallelism' ;

    /**
     * Type-specific structured options (currently used by `vector` to
     * carry the Faiss configuration: `dimensions`, `metric`, `nLists`,
     * `defaultNProbe`, `factory`, …).
     */
    public const string PARAMS = 'params' ;

    /**
     * Prefix attributes for an `mdi-prefixed` index — must be supplied
     * verbatim on every query that targets the index.
     */
    public const string PREFIX_FIELDS = 'prefixFields' ;

    /**
     * Whether the inverted index keeps a cache of primary-key values
     * (`inverted` only).
     */
    public const string PRIMARY_KEY_CACHE = 'primaryKeyCache' ;

    /**
     * Primary sort definition of an inverted index (`inverted` only).
     */
    public const string PRIMARY_SORT = 'primarySort' ;

    /**
     * Whether the inverted index is a search-only field (no document
     * lookup, `inverted` only).
     */
    public const string SEARCH_FIELD = 'searchField' ;

    /**
     * Whether to skip documents missing every indexed attribute
     * (`persistent` only).
     */
    public const string SPARSE = 'sparse' ;

    /**
     * Additional attribute paths kept alongside the index entries to
     * answer covering queries without touching the document
     * (`persistent`, `mdi`, `mdi-prefixed`, `inverted`, `vector`).
     */
    public const string STORED_VALUES = 'storedValues' ;

    /**
     * Whether the inverted index records the positions of each token
     * within the source field (`inverted` only).
     */
    public const string TRACK_LIST_POSITIONS = 'trackListPositions' ;

    /**
     * Index type marker — one of {@see IndexType}.
     */
    public const string TYPE = 'type' ;

    /**
     * Whether the index enforces uniqueness across indexed attribute
     * tuples (`persistent` only).
     */
    public const string UNIQUE = 'unique' ;
}
