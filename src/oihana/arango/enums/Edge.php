<?php

namespace oihana\arango\enums;

use oihana\reflect\traits\ConstantsTrait;

class Edge
{
    use ConstantsTrait ;
    
    public const string ANY  = 'any'  ;
    public const string FROM = 'from' ;
    public const string TO   = 'to'   ;

    /**
     * Document _from index
     */
    public const string ENTRY_FROM = '_from';

    /**
     * Revision _to index
     */
    public const string ENTRY_TO = '_to';
}