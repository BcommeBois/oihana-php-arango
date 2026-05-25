<?php

namespace oihana\arango\db\enums\options;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The values of the uniqueEdges option in the graph traversal options.
 * @see https://docs.arangodb.com/stable/aql/graphs/traversals
 */
class TraversalUniqueEdges
{
    use ConstantsTrait ;
    
    /**
     * It is guaranteed that there is no path returned with a duplicate edge.
     */
    public const string NONE = 'none' ;

    /**
     * No uniqueness check is applied on edges.
     * Note: Using this configuration, the traversal follows edges in cycles.
     */
    public const string PATH = 'path'   ;
}