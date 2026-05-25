<?php

namespace oihana\arango\db\options;

class ForOptions extends QueryOptions
{
    /**
     * In some rare cases it can be beneficial to not do an index lookup or scan, but to do a full collection scan.
     * An index lookup can be more expensive than a full collection scan if the index
     * lookup produces many (or even all documents) and the query cannot be satisfied from the index data alone.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/for/#disableindex
     */
    public ?bool $disableIndex ;

    /**
     * Index hints are not enforced by default. If forceIndexHint is set to true,
     * then an error is generated if indexHint does not contain a usable index,
     * instead of using a fallback index or not using an index at all.
     * <code>
     * FOR … IN … OPTIONS { indexHint: … , forceIndexHint: true }
     * </code>
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/for/#forceindexhint
     */
    public ?bool $forceIndexHint ;

    /**
     * For collections, index hints can be given to the optimizer with the indexHint option. The value can be a single index name or a list of index names in order of preference:
     * <code>
     * FOR … IN … OPTIONS { indexHint: "byName" }
     * </code>
     * <code>
     * FOR … IN … OPTIONS { indexHint: ["byName", "byColor"] }
     * </code>
     * Whenever there is a chance to potentially use an index for this FOR loop, the optimizer will first check if the specified index can be used.
     * In case of an array of indexes, the optimizer will check the feasibility of each index in the specified order.
     * It will use the first suitable index, regardless of whether it would normally use a different index.
     * If none of the specified indexes is suitable, then it falls back to its normal logic to select another index or fails if forceIndexHint is enabled.
     * @var string|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/for/#indexhint
     */
    public ?string $indexHint ;

    /**
     * The multi-dimensional index types mdi and mdi-prefixed support an optional index hint for tweaking performance:
     * <code>
     * FOR … IN … OPTIONS { lookahead: 32 }
     * </code>
     * @var int|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/for/#lookahead
     */
    public ?int $lookahead ;

    /**
     * By default, the query optimizer will consider up to 5 document attributes per FOR loop to be used as projections.
     * If more than 5 attributes of a collection are accessed in a FOR loop, the optimizer will prefer to extract
     * the full document and not use projections.
     * The threshold value of 5 attributes is arbitrary and can be adjusted by using the maxProjections hint.
     * The default value for maxProjections is 5, which is compatible with the previously hard-coded default value.
     * For example, using a maxProjections hint of 7, the following query will extract 7 attributes as projections from the original document:
     * <code>
     * FOR doc IN collection OPTIONS { maxProjections: 7 }
     * RETURN [ doc.val1, doc.val2, doc.val3, doc.val4, doc.val5, doc.val6, doc.val7 ]
     * </code>
     * Normally it is not necessary to adjust the value of maxProjections, but there are a few corner cases where it can make sense:
     * It can be beneficial to increase maxProjections when extracting many small attributes from very large documents,
     * and a full copy of the documents should be avoided.
     * It can be beneficial to decrease maxProjections to avoid using projections, if the cost of projections is higher
     * than doing copies of the full documents. This can be the case for very small documents.
     * @var int|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/for/#maxprojections
     */
    public ?int $maxProjections ;

    /**
     * You can disable in-memory caches that you may have enabled for persistent indexes on a case-by-case basis.
     * This is useful for queries that access indexes with enabled in-memory caches, but for which it is known
     * that using the cache will have a negative performance impact.
     * In this case, you can set the useCache hint to false:
     * <code>
     * FOR doc IN collection OPTIONS { useCache: false }
     * FILTER doc.value == @value
     * ...
     * </code>
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/for/#usecache
     */
    public ?bool $useCache ;
}