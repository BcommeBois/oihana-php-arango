<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

class CastingFunction
{
    use FunctionCallTrait ;

    public const string TO_ARRAY  = 'TO_ARRAY' ;
    public const string TO_BOOL   = 'TO_BOOL' ;
    public const string TO_NUMBER = 'TO_NUMBER' ;
    public const string TO_STRING = 'TO_STRING' ;
}
