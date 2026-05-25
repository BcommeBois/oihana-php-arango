<?php

namespace oihana\arango\db\options\indexes;

use oihana\arango\db\enums\IndexType;

/**
 * The options of a persistent index.
 */
class PersistentIndexOptions extends IndexOptions
{
    /**
     * This attribute controls whether an extra in-memory hash cache is created for the index.
     * The hash cache can be used to speed up index lookups.
     * The cache can only be used for queries that look up all index attributes via an equality lookup (==).
     * The hash cache cannot be used for range scans, partial lookups or sorting.
     *
     * The cache will be populated lazily upon reading data from the index.
     * Writing data into the collection or updating existing data will invalidate entries in the cache.
     * The cache may have a negative effect on performance in case index values are updated more often than they are read.
     *
     * The maximum size of cache entries that can be stored is currently 4 MB, i.e.
     * the cumulated size of all index entries for any index lookup value must be less than 4 MB.
     * This limitation is there to avoid storing the index entries of “super nodes” in the cache.
     *
     * cacheEnabled defaults to false and should only be used for indexes
     * that are known to benefit from an extra layer of caching.
     *
     * @var bool
     */
    public bool $cacheEnabled = false ;

    /**
     * The optional deduplicate attribute is supported by persistent array indexes.
     *
     * It controls whether inserting duplicate index values from the same document
     * into a unique array index will lead to a unique constraint error or not.
     *
     * The default value is true, so only a single instance of each non-unique index value
     * will be inserted into the index per document.
     *
     * Trying to insert a value into the index that already exists in the index always fails,
     * regardless of the value of this attribute.
     *
     * @var bool
     */
    public bool $deduplicate = true ;

    /**
     * This attribute controls whether index selectivity estimates are maintained for the index.
     *
     * Not maintaining index selectivity estimates can have a slightly positive impact on write performance.
     *
     * The downside of turning off index selectivity estimates is that the query optimizer is not able
     * to determine the usefulness of different competing indexes in AQL queries
     * when there are multiple candidate indexes to choose from.
     *
     * The option has no effect on indexes other than persistent, mdi, and mdi-prefixed.
     *
     * @var bool
     */
    public bool $estimates = true ;

    /**
     * Set this option to true to keep the collection/shards available for write operations
     * by not using an exclusive write lock for the duration of the index creation.
     *
     * @var bool
     */
    public bool $inBackground = false ;

    /**
     * Can be true or false.
     *
     * You can control the sparsity for persistent, mdi, and mdi-prefixed indexes.
     *
     * The inverted, fulltext, and geo index types are sparse by definition.
     *
     * @var bool
     */
    public bool $sparse = false  ;

    /**
     * The optional storedValues attribute can contain an array of paths to additional attributes to store in the index.
     *
     * These additional attributes cannot be used for index lookups or for sorting, but they can be used for projections.
     * This allows an index to fully cover more queries and avoid extra document lookups.
     *
     * The maximum number of attributes in storedValues is 32.
     *
     * It is not possible to create multiple indexes with the same fields attributes
     * and uniqueness but different storedValues attributes.
     * That means the value of storedValues is not considered by index creation calls when checking
     * if an index is already present or needs to be created.
     *
     * In unique indexes, only the attributes in fields are checked for uniqueness,
     * but the attributes in storedValues are not checked for their uniqueness.
     *
     * Non-existing attributes are stored as null values inside storedValues.
     *
     * @var ?array
     */
    public ?array $storedValues ;

    /**
     * Can be one of the following values:
     * - "persistent": persistent (array) index, including vertex-centric index
     * - "inverted": inverted index
     * - "ttl": time-to-live index
     * - "fulltext": full-text index (deprecated from ArangoDB 3.10 onwards)
     * - "geo": geo-spatial index, with one or two attributes
     * - "mdi": multi-dimensional index
     * - "mdi-prefixed": multi-dimensional index with search prefix, including vertex-centric index
     *
     * @var string
     */
    public string $type = IndexType::PERSISTENT ;

    /**
     * Whether to create the index with a uniqueness constraint.
     *
     * In unique indexes, only the attributes in fields are checked for uniqueness,
     * but the attributes in storedValues are not checked for their uniqueness.
     *
     * @var bool
     */
    public bool $unique = false ;
}