<?php

namespace oihana\arango\enums;

use oihana\reflect\traits\ConstantsTrait;

class Field
{
    use ConstantsTrait ;

    public const string ALTERS   = 'alters'   ;
    public const null   DEFAULT  = null       ;
    public const string EDGES    = 'edges'    ;
    public const string ELSE     = 'else'     ;
    public const string FIELDS   = 'fields'   ;
    public const string JOINS    = 'joins'    ;
    public const string FILTER   = 'filter'   ;
    public const string FORMAT   = 'format'   ;
    public const string NAME     = 'name'     ;
    public const string PATH     = 'path'     ;
    public const string PATHS    = 'paths'    ;
    public const string PROPERTY = 'property' ;
    public const string RAW      = 'raw'      ;
    public const string REQUIRES = 'requires' ;
    public const string QUOTED   = 'quoted'   ;
    public const string SCOPE    = 'scope'    ;
    public const string SKINS    = 'skins'    ;
    public const string UNIQUE   = 'unique'   ;
    public const string WHEN     = 'when'     ;
}


