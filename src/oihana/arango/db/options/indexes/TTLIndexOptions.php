<?php

namespace oihana\arango\db\options\indexes;

use oihana\arango\db\enums\IndexType;

/**
 * The options of a time-to-live (TTL) index.
 */
class TTLIndexOptions extends IndexOptions
{
    /**
     * The time interval (in seconds) from the point in time stored in the fields attribute
     * after which the documents count as expired.
     *
     * Can be set to 0 to let documents expire as soon as the server time passes the point in time stored
     * in the document attribute, or to a higher number to delay the expiration.
     *
     * @var int
     */
    public int $expireAfter ;

    /**
     * Set this option to true to keep the collection/shards available for write operations
     * by not using an exclusive write lock for the duration of the index creation.
     *
     * @var bool
     */
    public bool $inBackground ;

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
    public string $type = IndexType::TTL ;
}