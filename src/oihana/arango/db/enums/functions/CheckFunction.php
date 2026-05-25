<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

class CheckFunction
{
    use FunctionCallTrait ;
    
    public const string IS_ARRAY      = 'IS_ARRAY' ;
    public const string IS_BOOL       = 'IS_BOOL' ;
    public const string IS_DATESTRING = 'IS_DATESTRING' ;
    public const string IS_DOCUMENT   = 'IS_DOCUMENT' ;
    public const string IS_KEY        = 'IS_KEY' ;
    public const string IS_LIST       = 'IS_LIST' ;
    public const string IS_NULL       = 'IS_NULL' ;
    public const string IS_NUMBER     = 'IS_NUMBER' ;
    public const string IS_OBJECT     = 'IS_OBJECT' ;
    public const string IS_IPV4       = 'IS_IPV4' ;
    public const string IS_STRING     = 'IS_STRING' ;
    public const string TYPENAME      = 'TYPENAME' ;
}
