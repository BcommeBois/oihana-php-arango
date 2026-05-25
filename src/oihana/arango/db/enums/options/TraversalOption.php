<?php

namespace oihana\arango\db\enums\options;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of all FOR optional OPTIONS properties to modify the clause behavior.
 * @see https://docs.arangodb.com/3.10/aql/high-level-operations/for/
 */
class TraversalOption
{
    use ConstantsTrait ;
    
    public const string DEFAULT_WEIGHT     = 'defaultWeight'     ;
    public const string EDGE_COLLECTIONS   = 'edgeCollections'   ;
    public const string MAX_PROJECTIONS    = 'maxProjections'    ;
    public const string ORDER              = 'order'             ;
    public const string PARALLELISM        = 'parallelism'       ;
    public const string UNIQUE_EDGES       = 'uniqueEdges'       ;
    public const string UNIQUE_VERTICES    = 'uniqueVertices'    ;
    public const string VERTEX_COLLECTIONS = 'vertexCollections' ;
    public const string WEIGHT_ATTRIBUTE   = 'weightAttribute'   ;
}