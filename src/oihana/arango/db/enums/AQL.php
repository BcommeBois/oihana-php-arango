<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;
use xyz\oihana\schema\constants\traits\PaginationTrait;

class AQL
{
    use ConstantsTrait ,
        PaginationTrait ;

    public const string _DEPTH          = '_depth' ;
    public const string _PARENT         = '_parent' ;

    public const string AGGREGATE       = 'aggregate' ;
    public const string ALTERS          = 'alters' ;
    public const string ANALYZER        = 'analyzer' ;
    public const string ANY_REF         = 'anyRef' ;
    public const string ARRAY           = 'array' ;
    public const string ARRAYS          = 'arrays' ;
    public const string ASSIGN          = 'assign' ;
    public const string BINDS           = 'binds' ;
    public const string BOTH            = 'both' ;
    public const string BOUNDS          = 'bounds' ;
    public const string CHILDREN        = 'children' ;
    public const string COLLECTION      = 'collection' ;
    public const string CONDITIONS      = 'conditions' ;
    public const string CONTEXT         = 'context' ;
    public const string COUNT           = 'count' ;
    public const string DATABASE        = 'database' ;
    public const string DEFAULT         = 'default' ;
    public const string DIRECTION       = 'direction' ;
    public const string DOC             = 'doc' ;
    public const string DOC_JOIN        = 'doc_join' ;
    public const string DOC_PREFIX      = 'doc_' ;
    public const string DOC_REF         = 'docRef' ;
    public const string DOCUMENT        = 'document' ;
    public const string EDGE            = 'edge' ;
    public const string EDGE_COLLECTION = 'edgeCollection' ;
    public const string EDGE_REF        = 'edgeRef' ;
    public const string EDGES           = 'edges' ;
    public const string EXCLUDES        = 'excludes' ;
    public const string EXPRESSION      = 'expression' ;
    public const string FACETS          = 'facets' ;
    public const string FIELDS          = 'fields' ;
    public const string FILTER          = 'filter' ;
    public const string FILTERS         = 'filters' ;
    public const string FIRST           = 'first' ;
    public const string FOLLOWING       = 'following' ;
    public const string FROM            = 'from' ;
    public const string GRAPH           = 'graph' ;
    public const string GRAPH_DEFAULT   = 'vertex, edge, path' ;
    public const string ID              = 'id' ;
    public const string INDEXES         = 'indexes' ;
    public const string IN              = 'in' ;
    public const string INSERT          = 'insert' ;
    public const string INTO            = 'into' ;
    public const string IS_ARRAY        = 'isArray' ;
    public const string ITEM            = 'item' ;
    public const string JOIN            = 'join' ;
    public const string JOINS           = 'joins' ;
    public const string KEEP            = 'keep' ;
    public const string KEY             = 'key' ;
    public const string LAZY            = 'lazy' ;
    public const string LEAF            = 'leaf' ;
    public const string LENGTH          = 'length' ;
    public const string METHOD          = 'method' ;
    public const string MAX_DEPTH       = 'maxDepth' ;
    public const string MIN_DEPTH       = 'minDepth' ;
    public const string MOCK            = 'mock' ;
    public const string MODEL           = 'model' ;
    public const string NAME            = 'name' ;
    public const string NESTED          = 'nested' ;
    public const string NULL            = 'null' ;
    public const string OPERATOR        = 'operator' ;
    public const string OPTIONS         = 'options' ;
    public const string ORDER           = 'order' ;
    public const string PATH            = 'path' ;
    public const string PATH_REF        = 'pathRef' ;
    public const string PRECEDING       = 'preceding' ;
    public const string PREFIX          = 'prefix' ;
    public const string PROJECTION      = 'projection' ;
    public const string PROPERTY        = 'property' ;
    public const string PRUNE           = 'prune' ;
    public const string PURGE           = 'purge' ;
    public const string QUERY_ID        = 'queryId' ;
    public const string RANGE_VALUE     = 'rangeValue' ;
    public const string RAW             = 'raw' ;
    public const string RAW_KEYS        = 'rawKeys' ;
    public const string RAW_VALUES      = 'rawValues' ;
    public const string RELATION        = 'relation' ;
    public const string REPLACE         = 'replace' ;
    public const string REQUIRES        = 'requires' ;
    public const string RESULT          = 'result' ;
    public const string RETURN          = 'return' ;
    public const string SCHEMA          = 'schema' ;
    public const string SEARCH          = 'search' ;
    public const string SEARCHABLE      = 'searchable' ;
    public const string SEARCH_OPTIONS  = 'searchOptions' ;
    public const string SKIN            = 'skin' ;
    public const string SKIN_FIELDS     = 'skinFields' ;
    public const string SORT            = 'sort' ;
    public const string SORT_DEFAULT    = 'sortDefault' ;
    public const string SORTABLE        = 'sortable' ;
    public const string START_VERTEX    = 'startVertex' ;
    public const string TARGET          = 'target' ;
    public const string TO              = 'to' ;
    public const string TYPE            = 'type' ;
    public const string UNIQUE          = 'unique' ;
    public const string USE_SPACE       = 'useSpace' ;
    public const string UPDATE          = 'update' ;
    public const string VALUE           = 'value' ;
    public const string VALUES          = 'values' ;
    public const string VERTEX          = 'vertex' ;
    public const string VERTEX_REF      = 'vertexRef' ;
    public const string VERTICES        = 'vertices' ;
    public const string VIEW            = 'view' ;
    public const string WITH            = 'with' ;
    public const string WITH_COUNT      = 'withCount' ;
    public const string WITH_PATH       = 'withPath' ;

    // -------- binding

    public const string BIND_COLLECTION = '@collection' ;

    // -------- DI Container

    public const string RESOLVE = '__resolve__' ;

    // -------- variables

    public const string VAR_COLLECTION = '@@collection'  ;
}