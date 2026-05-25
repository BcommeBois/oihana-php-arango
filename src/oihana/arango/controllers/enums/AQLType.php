<?php

namespace oihana\arango\controllers\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of all types accepted in the "init" list of the controllers.
 */
final class AQLType
{
    use ConstantsTrait ;

    public const string ARRAY             = 'array'  ;
    public const string BOOL              = 'bool'   ;
    public const string DATE              = 'date'   ;
    public const string DOCUMENT          = 'document' ;
    public const string EDGE              = 'edge' ;
    public const string FLOAT             = 'float' ;
    public const string FLOAT_WITH_RANGE  = 'floatWithRange' ;
    public const string I18N              = 'i18n' ;
    public const string INT               = 'int' ;
    public const string INT_WITH_RANGE    = 'intWithRange' ;
    public const string JOIN              = 'join' ;
    public const string JOINS             = 'joins' ;
    public const string MODEL             = 'model' ;
    public const string NULL              = 'null' ;
    public const string OBJECT            = 'object' ;
    public const string PAYLOAD           = 'payload' ;
    public const string PATH              = 'path' ;
    public const string STRING            = 'string' ;
}