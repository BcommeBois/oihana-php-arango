<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class DateUnit
{
    use ConstantsTrait ;
    
    public const string D    = 'd' ;
    public const string DAY  = 'day' ;
    public const string DAYS = 'days' ;

    public const string F            = 'f' ;
    public const string MILLISECOND  = 'millisecond' ;
    public const string MILLISECONDS = 'milliseconds' ;

    public const string H     = 'h' ;
    public const string HOUR  = 'hour' ;
    public const string HOURS = 'hours' ;

    public const string I       = 'i' ;
    public const string MINUTE  = 'minute' ;
    public const string MINUTES = 'minutes' ;

    public const string M      = 'm' ;
    public const string MONTH  = 'month' ;
    public const string MONTHS = 'months' ;

    public const string S       = 's' ;
    public const string SECOND  = 'second' ;
    public const string SECONDS = 'seconds' ;

    public const string Y     = 'y' ;
    public const string YEAR  = 'year' ;
    public const string YEARS = 'years'  ;
}
