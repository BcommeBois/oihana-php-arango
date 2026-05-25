<?php

namespace oihana\arango\db\enums\options;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of all optionally specify which traversal algorithm to use.
 * @see https://docs.arangodb.com/3.10/aql/graphs/traversals
 */
class TraversalUniqueVertices
{
    use ConstantsTrait ;
    
    /**
     * It is guaranteed that each vertex is visited at most once during the traversal,
     * no matter how many paths lead from the start vertex to this one.
     */
    public const string GLOBAL = 'global' ;

    /**
     * No uniqueness check is applied on vertices.
     */
    public const string NONE = 'none' ;

    /**
     * It is guaranteed that there is no path returned with a duplicate vertex.
     */
    public const string PATH = 'path'   ;
}