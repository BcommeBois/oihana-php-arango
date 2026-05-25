<?php

namespace oihana\arango\db\enums\options;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of all optionally specify which traversal algorithm to use.
 * @see https://docs.arangodb.com/3.10/aql/graphs/traversals
 */
class TraversalOrder
{
    use ConstantsTrait ;
    
    /**
     * The traversal is executed breadth-first.
     * The results first contain all vertices at depth 1, then all vertices at depth 2 and so on.
     */
    public const string BFS = 'bfs' ;

    /**
     * The traversal is executed depth-first (default).
     * It first returns all paths from min depth to max depth for one vertex at depth 1, then for the next vertex at depth 1 and so on.
     */
    public const string DFS = 'dfs'   ;

    /**
     * The traversal is a weighted traversal.
     */
    public const string WEIGHTED = 'weighted' ;
}