<?php

namespace oihana\arango\db\options;

class ReplaceOptions extends QueryOptions
{
    /**
     * The RocksDB engine does not require collection-level locks. Different write operations
     * on the same collection do not block each other, as long as there are no write-write conflicts on the same documents.
     * From an application development perspective it can be desired to have exclusive write access on collections,
     * to simplify the development. Note that writes do not block reads in RocksDB.
     * Exclusive access can also speed up modification queries, because we avoid conflict checks.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace/#exclusive
     */
    public ?bool $exclusive ;

    /**
     * Suppress query errors that may occur when trying to update non-existing documents or when violating unique key constraints.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace/#ignoreerrors
     */
    public ?bool $ignoreErrors ;

    /**
     * In order to not accidentally overwrite documents that have been modified since you last fetched them,
     * you can use the option ignoreRevs to either let ArangoDB compare the _rev value and only succeed if they still match,
     * or let ArangoDB ignore them (default).
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace/#ignorerevs
     */
    public ?bool $ignoreRevs ;

    /**
     * Whether to update existing entries in in-memory index caches
     * if document updates affect the edge index or cache-enabled persistent indexes.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace/#refillindexcaches
     */
    public ?bool $refillIndexCaches ;

    /**
     * You can use the versionAttribute option for external versioning support.
     * If set, the attribute with the name specified by the option is looked up in the stored document
     * and the attribute value is compared numerically to the value of the versioning attribute
     * in the supplied document that is supposed to update it.
     *
     * For example, the following query conditionally updates an existing document with the key "123" if the attribute externalVersion currently has a value below 5:
     * <code>
     * UPDATE { _key: "123", externalVersion: 5, anotherAttribute: true }
     * IN coll
     * OPTIONS { versionAttribute: "externalVersion" }
     * </code>
     * You can check if OLD._rev and NEW._rev are different to determine if the document has been changed.
     * @var string|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace/#versionattribute
     */
    public ?string $versionAttribute ;

    /**
     * To make sure data are durable when an update query returns.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace/#waitforsync
     */
    public ?bool $waitForSync ;

}