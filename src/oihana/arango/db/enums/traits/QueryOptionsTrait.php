<?php

namespace oihana\arango\db\enums\traits;

use oihana\reflect\traits\ConstantsTrait;

trait QueryOptionsTrait
{
    use ConstantsTrait ;

    public const string CACHE_ENABLED       = 'cacheEnabled' ;
    public const string DEDUPLICATE         = 'deduplicate' ;
    public const string DISABLE_INDEX       = 'disableIndex' ;
    public const string ESTIMATES           = 'estimates' ;
    public const string EXCLUSIVE           = 'exclusive' ;
    public const string EXPIRE_AFTER        = 'expireAfter' ;
    public const string FIELDS              = 'fields' ;
    public const string FIELD_VALUE_TYPES   = 'fieldValueTypes' ;
    public const string FORCE_INDEX_HINT    = 'forceIndexHint' ;
    public const string GEO_JSON            = 'geoJson' ;
    public const string IGNORE_ERRORS       = 'ignoreErrors' ;
    public const string IGNORE_REVS         = 'ignoreRevs' ;
    public const string IN_BACKGROUND       = 'inBackground' ;
    public const string INDEX_HINT          = 'indexHint' ;
    public const string KEEP_NULL           = 'keepNull' ;
    public const string LEGACY_POLYGONS     = 'legacyPolygons' ;
    public const string LOOKAHEAD           = 'lookahead' ;
    public const string MAX_PROJECTIONS     = 'maxProjections' ;
    public const string MERGE_OBJECTS       = 'mergeObjects' ;
    public const string METHOD              = 'method' ;
    public const string NAME                = 'name' ;
    public const string OVERWRITE_MODE      = 'overwriteMode' ;
    public const string PARALLELISM         = 'parallelism' ;
    public const string PARAMS              = 'params' ;
    public const string PREFIX_FIELDS       = 'prefixFields' ;
    public const string READ_OWN_WRITES     = 'readOwnWrites' ;
    public const string REFILL_INDEX_CACHES = 'refillIndexCaches' ;
    public const string SPARSE              = 'sparse' ;
    public const string STORED_VALUE        = 'storedValue' ;
    public const string TYPE                = 'type' ;
    public const string UNIQUE              = 'unique' ;
    public const string USE_CACHE           = 'useCache' ;
    public const string WAIT_FOR_SYNC       = 'waitForSync' ;
}