<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

/**
 * AQL functions that do not fall into other categories are listed here.
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous
 */
class MiscFunction
{
    use FunctionCallTrait ;
    
    // ----- Control flow functions

    public const string FIRST_DOCUMENT = 'FIRST_DOCUMENT' ;
    public const string FIRST_LIST     = 'FIRST_LIST' ;
    public const string MIN_MATCH      = 'MIN_MATCH' ;
    public const string NOT_NULL       = 'NOT_NULL' ;

    // ----- Databases functions

    public const string CHECK_DOCUMENT   = 'CHECK_DOCUMENT' ;
    public const string COLLECTION_COUNT = 'COLLECTION_COUNT' ;
    public const string COLLECTIONS      = 'COLLECTIONS' ;
    public const string COUNT            = 'COUNT' ;
    public const string CURRENT_DATABASE = 'CURRENT_DATABASE' ;
    public const string CURRENT_USER     = 'CURRENT_USER' ;
    public const string DECODE_REV       = 'DECODE_REV' ;
    public const string DOCUMENT         = 'DOCUMENT' ;
    public const string LENGTH           = 'LENGTH' ;
    public const string SHARD_ID         = 'SHARD_ID' ;

    // ----- Hash functions

    public const string HASH          = 'HASH' ;
    public const string MINHASH       = 'MINHASH' ;
    public const string MINHASH_COUNT = 'MINHASH_COUNT' ;
    public const string MINHASH_ERROR = 'MINHASH_ERROR' ;

    // ----- Function calling

    public const string APPLY = 'APPLY' ;
    public const string CALL  = 'CALL' ;

    // ----- Other functions

    public const string ASSERT   = 'ASSERT' ;
    public const string WARN     = 'WARN' ;
    public const string IN_RANGE = 'IN_RANGE' ;

    // ----- Internal functions

    public const string FAIL            = 'FAIL' ;
    public const string NOEVAL          = 'NOEVAL' ;
    public const string NOOPT           = 'NOOPT' ;
    public const string PASSTHRU        = 'PASSTHRU' ;
    public const string SCHEMA_GET      = 'SCHEMA_GET' ;
    public const string SCHEMA_VALIDATE = 'SCHEMA_VALIDATE' ;
    public const string SLEEP           = 'SLEEP' ;
    public const string V8              = 'V8' ;
    public const string VERSION         = 'VERSION' ;

}
