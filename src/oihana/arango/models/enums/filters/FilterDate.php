<?php

namespace oihana\arango\models\enums\filters;

use oihana\reflect\traits\ConstantsTrait;

class FilterDate
{
    use ConstantsTrait ;

    public const string CURRENT_TIMESTAMP = 'cts'       ;
    public const string NOW               = 'now'       ;
    public const string TOMORROW          = 'tomorrow'  ;
    public const string YESTERDAY         = 'yesterday' ;

    public const string DAY           = 'd'     ;
    public const string DAY_OF_WEEK   = 'dw'    ;
    public const string DAY_OF_YEAR   = 'dy'    ;
    public const string DAYS_IN_MONTH = 'dm'    ;
    public const string FORMAT        = 'f'     ;
    public const string HOUR          = 'h'     ;
    public const string ISO8601       = 'iso8601' ;
    public const string ISO_WEEK      = 'iw'    ;
    public const string LEAP_YEAR     = 'leap'  ;
    public const string MILLISECOND   = 'ms'    ;
    public const string MINUTE        = 'mn'    ;
    public const string MONTH         = 'm'     ;
    public const string QUARTER       = 'q'     ;
    public const string SECOND        = 's'     ;
    public const string TIMESTAMP     = 'ts'    ;
    public const string TRUNC         = 'trunc' ;
    public const string YEAR          = 'y'     ;
}