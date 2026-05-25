<?php

namespace oihana\arango\db\options\indexes;

use oihana\arango\db\enums\FaithParam;
use oihana\arango\db\enums\IndexType;

/**
 * The options of a vector index.
 */
class VectorIndexOptions extends IndexOptions
{
    /**
     * If you create a geo-spatial index over a single attribute and geoJson is true,
     * then the coordinate order within the attribute’s array is longitude followed by latitude.
     *
     * This corresponds to the format described in http://geojson.org/geojson-spec.html#positions
     *
     * @var bool
     */
    public bool $geoJson = false ;

    /**
     * Set this option to true to keep the collection/shards available for write operations
     * by not using an exclusive write lock for the duration of the index creation.
     *
     * @var bool
     */
    public bool $inBackground = false ;

    /**
     * The number of threads to use for indexing.
     * @var int
     */
    public int $parallelism = 2 ;

    /**
     * The parameters as used by the Faiss library.
     *
     * @var array
     *
     * @see FaithParam
     */
    public array $params = [] ;

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
    public string $type = IndexType::VECTOR ;
}