<?php

namespace oihana\arango\db\options\indexes;

use oihana\arango\db\enums\IndexType;

/**
 * The options of a multi-dimensional index.
 *
 * @see https://docs.arango.ai/arangodb/stable/develop/http-api/indexes/multi-dimensional/
 */
class MDIIndexOptions extends IndexOptions
{
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
     * Must be equal to "double". Currently only doubles are supported as values.
     *
     * @var string
     */
    public string $fieldValueTypes ;

    /**
     * Set this option to true to keep the collection/shards available for write operations
     * by not using an exclusive write lock for the duration of the index creation.
     *
     * @var bool
     */
    public bool $inBackground = false ;

    /**
     * Requires type to be "mdi-prefixed", and prefixFields needs to be set in this case.
     *
     * An array of attribute names used as search prefix. Array expansions are not allowed.
     *
     * @var array
     */
    public array $prefixFields = [] ;

    /**
     * Can be true or false.
     *
     * You can control the sparsity for persistent, mdi, and mdi-prefixed indexes.
     *
     * The inverted, fulltext, and geo index types are sparse by definition.
     *
     * @var bool
     */
    public bool $sparse = false ;

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
     * @var array
     */
    public array $storedValues = [] ;

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
    public string $type = IndexType::MDI ; // can be IndexType::MDI | IndexType::MDI_PREFIXED

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