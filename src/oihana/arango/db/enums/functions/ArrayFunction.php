<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

/**
 * AQL provides functions for higher-level array manipulation in addition to language constructs that can also be used for arrays.
 * @see https://docs.arangodb.com/stable/aql/functions/array
 */
class ArrayFunction
{
    use FunctionCallTrait ;
    
    public const string APPEND         = 'APPEND' ;
    public const string CONTAINS_ARRAY = 'COUNT' ;
    public const string COUNT          = 'COUNT' ;
    public const string COUNT_DISTINCT = 'COUNT_DISTINCT' ;
    public const string COUNT_UNIQUE   = 'COUNT_UNIQUE' ;
    public const string FIRST          = 'FIRST' ;
    public const string FLATTEN        = 'FLATTEN' ;
    public const string INTERLEAVE     = 'INTERLEAVE' ;
    public const string INTERSECTION   = 'INTERSECTION' ;
    public const string JACCARD        = 'JACCARD' ;
    public const string LAST           = 'LAST' ;
    public const string LENGTH         = 'LENGTH' ;
    public const string MINUS          = 'MINUS'  ;
    public const string NTH            = 'NTH' ;
    public const string OUTERSECTION   = 'OUTERSECTION' ;
    public const string POP            = 'POP' ;
    public const string POSITION       = 'POSITION' ;
    public const string PUSH           = 'PUSH' ;
    public const string REMOVE_NTH     = 'REMOVE_NTH' ;
    public const string REPLACE_NTH    = 'REPLACE_NTH' ;
    public const string REMOVE_VALUE   = 'REMOVE_VALUE' ;
    public const string REMOVE_VALUES  = 'REMOVE_VALUES' ;
    public const string REVERSE        = 'REVERSE' ;
    public const string SHIFT          = 'SHIFT' ;
    public const string SLICE          = 'SLICE' ;
    public const string SORTED         = 'SORTED' ;
    public const string SORTED_UNIQUE  = 'SORTED_UNIQUE' ;
    public const string UNION          = 'UNION' ;
    public const string UNION_DISTINCT = 'UNION_DISTINCT' ;
    public const string UNIQUE         = 'UNIQUE' ;
    public const string UNSHIFT        = 'UNSHIFT' ;
}
