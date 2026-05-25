<?php

namespace oihana\arango\models\enums\filters;

use oihana\reflect\traits\ConstantsTrait;

class FilterMatch
{
    use ConstantsTrait ;

    /**
     * The 'all' match flag.
     */
    public const string ALL = 'all' ;

    /**
     * The 'any' match flag.
     */
    public const string ANY = 'any' ;

    /**
     * The 'none' match flag.
     */
    public const string NONE = 'none' ;
}