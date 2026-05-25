<?php

namespace oihana\arango\db\enums\traits;

use oihana\reflect\traits\ConstantsTrait;

trait IndexOptionsTrait
{
    use ConstantsTrait ;

    public const string CACHE_ENABLED       = 'cacheEnabled' ;
    public const string DEDUPLICATE         = 'deduplicate' ;
    public const string ESTIMATES           = 'estimates' ;
    public const string EXPIRE_AFTER        = 'expireAfter' ;
    public const string FIELDS              = 'fields' ;
    public const string FIELD_VALUE_TYPES   = 'fieldValueTypes' ;
    public const string GEO_JSON            = 'geoJson' ;
    public const string IN_BACKGROUND       = 'inBackground' ;
    public const string LEGACY_POLYGONS     = 'legacyPolygons' ;
    public const string NAME                = 'name' ;
    public const string PARALLELISM         = 'parallelism' ;
    public const string PARAMS              = 'params' ;
    public const string PREFIX_FIELDS       = 'prefixFields' ;
    public const string SPARSE              = 'sparse' ;
    public const string STORED_VALUE        = 'storedValue' ;
    public const string TYPE                = 'type' ;
    public const string UNIQUE              = 'unique' ;
}