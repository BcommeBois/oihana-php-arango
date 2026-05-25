<?php

namespace oihana\arango\db\options;

class TraversalOptions extends QueryOptions
{
    /**
     * Specifies the default weight of an edge (number). The default value is 1.
     *
     * The value must not be negative.
     *
     * @var int|null
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#defaultweight
     */
    public ?int $defaultWeight ;

    /**
     * Restrict edge collections the traversal may visit (string|array).
     *
     * If omitted or an empty array is specified, then there are no restrictions.
     *
     * A string parameter is treated as the equivalent of an array with a single element.
     * Each element of the array should be a string containing the name of an edge collection.
     *
     * @var string|array|null
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#edgecollections
     */
    public null|string|array $edgeCollections ;

    /**
     * You can provide index hints for traversals to let the optimizer prefer the vertex-centric indexes you specify
     * over the regular edge index.
     *
     * This is useful for cases where the selectively estimate of the edge index is higher than the ones
     * for suitable vertex-centric indexes (and thus they aren’t picked automatically) but the vertex-centric indexes are known to perform better.
     *
     * The indexHint option expects an object in the following format:
     *
     * { "<edgeColl>": { "<direction>": { "<level>": <index> } } }
     *
     * <edgeColl>: The name of an edge collection for which the index hint shall be applied. Collection names are case-sensitive.
     * <direction>: The direction for which to apply the index hint. Valid values are inbound and outbound, in lowercase. You can specify indexes for both directions.
     * <level>: The level/depth for which the index should be applied. Valid values are the string base (to define the default index for all levels) and any stringified integer values greater or equal to zero. You can specify multiple levels.
     * <index>: The name of an index as a string, or multiple index names as a list of strings in the order of preference. The optimizer uses the first suitable index.
     *
     * @var object|null
     *
     * @example
     * ```aql
     * FOR v, e, p IN 1..4 OUTBOUND startNode edgeCollection
     * OPTIONS {
     *   indexHint: {
     *     "edgeCollection": {
     *       "outbound": {
     *         "base": ["edge"],
     *         "1": "myIndex1",
     *         "2": ["myIndex2", "myIndex1"],
     *         "3": "myIndex3",
     *       }
     *     }
     *   }
     * }
     * FILTER p.edges[1].foo == "bar" AND p.edges[2].foo == "bar" AND p.edges[2].baz == "qux"
     * ```
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#indexhint
     */
    public null|object $indexHint ;

    /**
     * Specifies the number of document attributes per FOR loop to be used as projections (number).
     * The default value is 5.
     *
     * The AQL optimizer automatically detects which document attributes you access
     * in traversal queries and optimizes the data loading.
     *
     * This optimization is beneficial if you have large documents but only access a few document attributes.
     * The maxProjections option lets you tune when to load individual attributes versus the whole document.
     *
     * @var int|null
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#maxprojections
     */
    public ?int $maxProjections ;

    /**
     * Specify which traversal algorithm to use (string):
     * - "bfs" – the traversal is executed breadth-first.
     *           The results first contain all vertices at depth 1, then all vertices at depth 2 and so on.
     * - "dfs" (default) – the traversal is executed depth-first.
     *                     It first returns all paths from min depth to max depth for one vertex at depth 1,
     *                     then for the next vertex at depth 1 and so on.
     * "weighted" - the traversal is a weighted traversal (introduced in v3.8.0).
     *              Paths are enumerated with increasing cost.
     *              Also see weightAttribute and defaultWeight.
     *              A returned path has an additional attribute weight containing the cost of the path after every step.
     *              The order of paths having the same cost is non-deterministic.
     *              Negative weights are not supported and abort the query with an error.
     *
     * @var ?string
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#order
     */
    public ?string $order ;

    /**
     * Parallelize traversal execution (number).
     *
     * If omitted or set to a value of 1, the traversal execution is not parallelized.
     * If set to a value greater than 1, then up to that many worker threads
     * can be used for concurrently executing the traversal.
     *
     * The value is capped by the number of available cores on the target machine.
     *
     * Parallelizing a traversal is normally useful when there are many inputs (start nodes)
     * that the nested traversal can work on concurrently.
     * This is often the case when a nested traversal is fed with several tens of thousands
     * of start nodes, which can then be distributed randomly to worker threads for parallel execution.
     *
     * @var int|null
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#parallelism
     */
    public ?int $parallelism ;

    /**
     * Ensure edge uniqueness (string):
     *
     * - "path" (default) – it is guaranteed that there is no path returned with a duplicate edge
     * - "none" – no uniqueness check is applied on edges.
     *            Note: Using this configuration, the traversal follows edges in cycles.
     *
     * @var string|null
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#uniqueedges
     */
    public ?string $uniqueEdges ;

    /**
     * Ensure node uniqueness (string):
     *
     * - "path" – it is guaranteed that there is no path returned with a duplicate vertex
     * - "global" – it is guaranteed that each vertex is visited at most once during the traversal,
     *              no matter how many paths lead from the start vertex to this one. If you start with a min depth > 1 a vertex that was found before min depth might not be returned at all (it still might be part of a path). It is required to set order: "bfs" or order: "weighted" because with depth-first search the results would be unpredictable. Note: Using this configuration the result is not deterministic any more. If there are multiple paths from startVertex to vertex, one of those is picked. In case of a weighted traversal, the path with the lowest weight is picked, but in case of equal weights it is undefined which one is chosen.
     * - "none" (default) – no uniqueness check is applied on vertices
     *
     * @var string|null
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#uniquevertices
     */
    public ?string $uniqueVertices ;

    /**
     * Whether to use the in-memory cache for edges. The default is true.
     *
     * You can set this option to false to not make a large graph operation pollute the edge cache.
     *
     * @var bool|null
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#usecache
     */
    public ?bool $useCache ;

    /**
     * Restrict node collections the traversal may visit (string|array).
     *
     * If omitted or an empty array is specified, then there are no restrictions.
     *
     * A string parameter is treated as the equivalent of an array with a single element.
     * Each element of the array should be a string containing the name of a node collection.
     * The starting node is always allowed, even if it does not belong to one of the collections specified by a restriction.
     *
     * @var null|string|array
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#vertexcollections
     */
    public null|string|array $vertexCollections ;

    /**
     * Specifies the name of an attribute that is used to look up the weight of an edge (string).
     *
     * If no attribute is specified or if it is not present in the edge document then the defaultWeight is used.
     *
     * The attribute value must not be negative.
     *
     * @var string|null
     *
     * @see https://docs.arangodb.com/stable/aql/graphs/traversals/#weightattribute
     */
    public ?string $weightAttribute ;
}