<?php

namespace oihana\arango\db\options;

class RemoveOptions extends QueryOptions
{
    /**
     * The RocksDB engine does not require collection-level locks. Different write operations
     * on the same collection do not block each other, as long as there are no write-write conflicts on the same documents.
     * From an application development perspective it can be desired to have exclusive write access on collections,
     * to simplify the development. Note that writes do not block reads in RocksDB.
     * Exclusive access can also speed up modification queries, because we avoid conflict checks.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/remove/#exclusive
     */
    public bool $exclusive ;

    /**
     * Suppress query errors that may occur when trying to update non-existing documents or when violating unique key constraints.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#ignoreerrors
     */
    public bool $ignoreErrors ;

    /**
     * In order to not accidentally overwrite documents that have been modified since you last fetched them,
     * you can use the option ignoreRevs to either let ArangoDB compare the _rev value and only succeed if they still match,
     * or let ArangoDB ignore them (default).
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#ignorerevs
     */
    public bool $ignoreRevs ;

    /**
     * Whether to delete existing entries from in-memory index caches and refill them
     * if document removals affect the edge index or cache-enabled persistent indexes.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/remove/#refillindexcaches
     */
    public bool $refillIndexCaches ;

    /**
     * To make sure data are durable when an update query returns.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#waitforsync
     */
    public bool $waitForSync ;
}