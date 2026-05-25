<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

/**
 * AQL includes functions to work with dates as numeric timestamps and as ISO 8601 date time strings.
 * @see https://docs.arangodb.com/stable/aql/functions/date
 */
class DateFunction
{
    use FunctionCallTrait ;
    
    // ----- current date

    public const string DATE_NOW = 'DATE_NOW' ;

    // ----- comparison

    public const string DATE_ADD        = 'DATE_ADD'        ;
    public const string DATE_COMPARE    = 'DATE_COMPARE'    ;
    public const string DATE_DIFF       = 'DATE_DIFF'       ;
    public const string DATE_LOCALTOUTC = 'DATE_LOCALTOUTC' ;
    public const string DATE_SUBTRACT   = 'DATE_SUBTRACT'   ;
    public const string DATE_TIMEZONE   = 'DATE_TIMEZONE'   ;
    public const string DATE_TIMEZONES  = 'DATE_TIMEZONES'  ;
    public const string DATE_UTCTOLOCAL = 'DATE_UTCTOLOCAL' ;

    // ----- conversion

    public const string DATE_ISO8601   = 'DATE_ISO8601'   ;
    public const string DATE_TIMESTAMP = 'DATE_TIMESTAMP' ;
    public const string IS_DATESTRING  = 'IS_DATESTRING'  ;

    // ----- processing

    public const string DATE_FORMAT        = 'DATE_FORMAT' ;
    public const string DATE_DAY           = 'DATE_DAY' ;
    public const string DATE_DAYOFWEEK     = 'DATE_DAYOFWEEK' ;
    public const string DATE_DAYOFYEAR     = 'DATE_DAYOFYEAR' ;
    public const string DATE_DAYS_IN_MONTH = 'DATE_DAYS_IN_MONTH' ;
    public const string DATE_HOUR          = 'DATE_HOUR' ;
    public const string DATE_ISOWEEK       = 'DATE_ISOWEEK' ;
    public const string DATE_ISOWEEKYEAR   = 'DATE_ISOWEEKYEAR' ;
    public const string DATE_LEAPYEAR      = 'DATE_LEAPYEAR' ;
    public const string DATE_MINUTE        = 'DATE_MINUTE' ;
    public const string DATE_MILLISECOND   = 'DATE_MILLISECOND' ;
    public const string DATE_MONTH         = 'DATE_MONTH' ;
    public const string DATE_QUARTER       = 'DATE_QUARTER' ;
    public const string DATE_ROUND         = 'DATE_ROUND' ;
    public const string DATE_SECOND        = 'DATE_SECOND' ;
    public const string DATE_TRUNC         = 'DATE_TRUNC' ;
    public const string DATE_YEAR          = 'DATE_YEAR' ;
}
