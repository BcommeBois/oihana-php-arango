<?php

namespace oihana\arango\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The filter enumeration.
 */
class Filter
{
    use ConstantsTrait ;

    public const string ARRAY                 = 'array' ;
    public const string ARRAY_COUNT           = 'array_count' ;
    public const string ARRAY_EXPANSION       = 'array_expansion' ;
    public const string ARRAY_FIRST           = 'array_first' ;
    public const string BOOL                  = 'bool' ;
    public const string CAST                  = 'cast' ;
    public const string CAST_CHAR             = 'cast_char' ;
    public const string CAST_DOUBLE_PRECISION = 'cast_double_precision' ;
    public const string CAST_FLOAT            = 'cast_float' ;
    public const string CAST_INT              = 'cast_int' ;
    public const string CAST_NUMERIC          = 'cast_numeric' ;
    public const string CAST_TINY_INT         = 'cast_tiny_int' ;
    public const string CONCAT                = 'concat' ;
    public const string DATE                  = 'date' ;
    public const string DATETIME              = 'dateTime' ;
    public const null   DEFAULT               = null ;
    public const string DISTANCE              = 'distance' ;
    public const string DOCUMENT              = 'document' ;
    public const string EDGE                  = 'edge' ;
    public const string EDGES_COUNT           = 'edgeCount' ;
    public const string EDGES                 = 'edges' ;
    public const string FILTER                = 'filter' ;
    public const string FLOAT                 = 'float' ;
    public const string ID                    = 'id' ;
    public const string IMAGE                 = 'image' ;
    public const string MAP                   = 'map' ;
    public const string NUMBER                = 'number' ;
    public const string LOWER                 = 'lower' ;
    public const string JOIN                  = 'join' ;
    public const string JOINS                 = 'joins' ;
    public const string JOIN_ARRAY            = 'join_array' ;
    public const string JOINS_COUNT           = 'join_count' ;
    public const string JOIN_MULTIPLE         = 'join_multiple' ;
    public const string MEDIA_SOURCE          = 'media_source' ;
    public const string MEDIA_THUMBNAIL       = 'media_thumbnail' ;
    public const string MEDIA_URL             = 'media_url' ;
    public const string PERMISSIONS           = 'permissions' ;
    public const string PHOTOS                = 'photos' ;
    public const string PRECISION             = 'precision' ;
    public const string REVISION              = 'revision' ;
    public const string SCALE                 = 'scale' ;
    public const string STRING                = 'string' ;
    public const string THESAURUS_IMAGE       = 'thesaurus_image' ;
    public const string THESAURUS_URL         = 'thesaurus_url' ;
    public const string TRANSLATE             = 'translate' ;
    public const string TYPE                  = 'type' ;
    public const string UPPER                 = 'upper' ;
    public const string URL                   = 'url' ;
    public const string URL_API               = 'url_api' ;
    public const string UNIQUE_NAME           = 'unique_name' ;
    public const string VALUE                 = 'value' ;
    public const string WRAP                  = 'wrap' ;
}


