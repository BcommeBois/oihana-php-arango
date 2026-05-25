<?php

namespace oihana\arango\db\options;

use oihana\arango\db\enums\OverwriteMode;

class InsertOptions extends QueryOptions
{
    /**
     * The RocksDB engine does not require collection-level locks. Different write operations
     * on the same collection do not block each other, as long as there are no write-write conflicts on the same documents.
     * From an application development perspective it can be desired to have exclusive write access on collections,
     * to simplify the development. Note that writes do not block reads in RocksDB.
     * Exclusive access can also speed up modification queries, because we avoid conflict checks.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/insert/#exclusive
     */
    public ?bool $exclusive ;

    /**
     * Suppress query errors that may occur when trying to update non-existing documents or when violating unique key constraints.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/insert/#ignoreerrors
     */
    public ?bool $ignoreErrors ;

    /**
     * To further control the behavior of INSERT on primary index unique constraint violations, there is the overwriteMode option. It offers the following modes:
     *
     * - "ignore": if a document with the specified _key value exists already, nothing will be done and no write operation will be carried out. The insert operation will return success in this case. This mode does not support returning the old document version. Using RETURN OLD will trigger a parse error, as there will be no old version to return. RETURN NEW will only return the document in case it was inserted. In case the document already existed, RETURN NEW will return null.
     * - "replace": if a document with the specified _key value exists already, it will be overwritten with the specified document value. This mode will also be used when no overwrite mode is specified but the overwrite flag is set to true.
     * - "update": if a document with the specified _key value exists already, it will be patched (partially updated) with the specified document value.
     * - "conflict": if a document with the specified _key value exists already, return a unique constraint violation error so that the insert operation fails. This is also the default behavior in case the overwrite mode is not set, and the overwrite flag is false or not set either.
     *
     * The main use case of inserting documents with overwrite mode ignore is to make sure that
     * certain documents exist in the cheapest possible way.
     * In case the target document already exists, the ignore mode is most efficient, as it will not retrieve
     * the existing document from storage and not write any updates to it.
     *
     * When using the update overwrite mode, the keepNull and mergeObjects options control how the update is done. See UPDATE operation.
     *<code>
     * FOR i IN 1..1000
     * INSERT { _key: CONCAT('test', i), name: "test", foobar: true }
     * INTO users OPTIONS { overwriteMode: "update", keepNull: true, mergeObjects: false }
     * </code>
     * @var ?string
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/insert/#overwritemode
     */
    public ?string $overwriteMode
    {
        get => $this->overwriteMode ?? null ;
        set( ?string $value )
        {
            $this->overwriteMode = OverwriteMode::get( $value );
        }
    }

    /**
     * Whether to add new entries to in-memory index caches if document insertions affect the edge index or cache-enabled persistent indexes.
     * <code>
     * INSERT { _from: "vert/A", _to: "vert/B" } INTO coll
     * OPTIONS { refillIndexCaches: true }
     * </code>
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/remove/#refillindexcaches
     */
    public ?bool $refillIndexCaches ;

    /**
     * Only applicable if overwrite is set to true or overwriteMode is set to update or replace.
     *
     * You can use the versionAttribute option for external versioning support.
     * If set, the attribute with the name specified by the option is looked up in the stored document and
     * the attribute value is compared numerically to the value of the versioning attribute in the supplied document
     * that is supposed to update/replace it.
     *
     * If the version number in the new document is higher (rounded down to a whole number) than in the document
     * that already exists in the database, then the update/replace operation is performed normally.
     * This is also the case if the new versioning attribute has a non-numeric value, if it is a negative number,
     * or if the attribute doesn’t exist in the supplied or stored document.
     *
     * If the version number in the new document is lower or equal to what exists in the database,
     * the operation is not performed and the existing document thus not changed.
     * No error is returned in this case. The attribute can only be a top-level attribute.
     *
     * For example, the following query conditionally replaces an existing document with the key "123" if the attribute externalVersion currently has a value below 5:
     * <code>
     * INSERT { _key: "123", externalVersion: 5, anotherAttribute: true } IN coll
     * OPTIONS { overwriteMode: "replace", versionAttribute: "externalVersion" }
     * </code>
     *
     * You can check if OLD._rev (if not null) and NEW._rev are different to determine if the document has been changed.
     * @var string|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/insert/#versionattribute
     */
    public ?string $versionAttribute ;

    /**
     * To make sure data are durable when an update query returns.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/insert/#waitforsync
     */
    public ?bool $waitForSync ;
}