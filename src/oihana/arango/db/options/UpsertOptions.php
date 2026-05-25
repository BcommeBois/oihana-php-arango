<?php

namespace oihana\arango\db\options;

class UpsertOptions extends QueryOptions
{
    /**
     * The RocksDB engine does not require collection-level locks. Different write operations
     * on the same collection do not block each other, as long as there are no write-write conflicts on the same documents.
     * From an application development perspective it can be desired to have exclusive write access on collections,
     * to simplify the development. Note that writes do not block reads in RocksDB.
     * Exclusive access can also speed up modification queries, because we avoid conflict checks.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#exclusive
     */
    public ?bool $exclusive ;

    /**
     * Makes the index or indexes specified in indexHint mandatory if enabled. The default is false.
     * Also see forceIndexHint Option of the FOR Operation.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#forceindexhint
     */
    public ?bool $forceIndexHint ;

    /**
     * When updating an attribute to the null value, ArangoDB does not remove the attribute from the document but stores this null value.
     * To remove attributes in an update operation, set them to null and set the keepNull option to false.
     * Only top-level attributes and sub-attributes can be removed this way (e.g. { attr: { sub: null } })
     * but not attributes of objects that are nested inside of arrays (e.g. { attr: [ { nested: null } ] }).
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#keepnull
     */
    public ?bool $keepNull ;

    /**
     * The indexHint option is used as a hint for the document lookup performed as part of the UPSERT operation,
     * and can help in cases such as UPSERT not picking the best index automatically.
     * <code>
     * UPSERT { a: 1234 }
     * INSERT { a: 1234, name: "AB" }
     * UPDATE { name: "ABC" } IN myCollection
     * OPTIONS { indexHint: "index_name" }
     * </code>
     * The index hint is passed through to an internal FOR loop that is used for the lookup.
     * Also see indexHint Option of the FOR Operation.
     * Inverted indexes cannot be used for UPSERT lookups.
     * @var string|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#indexhint
     */
    public ?string $indexHint ;

    /**
     * Suppress query errors that may occur when trying to update non-existing documents or when violating unique key constraints.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#ignoreerrors
     */
    public ?bool $ignoreErrors ;

    /**
     * In order to not accidentally overwrite documents that have been modified since you last fetched them,
     * you can use the option ignoreRevs to either let ArangoDB compare the _rev value and only succeed if they still match,
     * or let ArangoDB ignore them (default).
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#ignorerevs
     */
    public ?bool $ignoreRevs ;

    /**
     * The option mergeObjects controls whether object contents are merged
     * if an object attribute is present in both the UPDATE query and in the to-be-updated document.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#mergeobjects
     */
    public ?bool $mergeObjects ;

    /**
     * The readOwnWrites option allows an UPSERT operation to process its inputs one by one.
     * The default value is true.
     * When enabled, the UPSERT operation can observe its own writes and can handle modifying
     * the same target document multiple times in the same query.
     * When the option is set to false, an UPSERT operation processes its inputs in batches.
     * Normally, a batch has 1000 inputs, which can lead to a faster execution.
     * However, when using batches, the UPSERT operation can essentially not observe its own writes.
     * You should only set the readOwnWrites option to false if you can guarantee that the input of the UPSERT leads to disjoint documents being inserted, updated, or replaced.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#mergeobjects
     */
    public ?bool $readOwnWrites ;

    /**
     * To make sure data are durable when an update query returns.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert/#waitforsync
     */
    public ?bool $waitForSync ;
}