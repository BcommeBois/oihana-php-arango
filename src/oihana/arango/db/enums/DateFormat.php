<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class DateFormat
{
    use ConstantsTrait ;

    public const string DAY                = '%d' ; // day (1..31)
    public const string DAY_PADDED         = '%dd' ; // day (01..31), padded to length of 2
    public const string DAY_OF_WEEK        = '%w' ; // day of week (0..6)
    public const string DAY_OF_YEAR        = '%x' ; // day of year (1..366)
    public const string DAY_OF_YEAR_PADDED = '%xxx' ; // day of year (001..366), padded to length of 3
    public const string DAYS_IN_MONTHS     = '%a' ; // days in month (28..31)
    public const string TIMESTAMP          = '%t' ; // timestamp, in milliseconds since midnight 1970-01-01
    public const string HOUR               = '%h' ; // hour (0..23)
    public const string HOUR_PADDED        = '%hh' ; // hour (00..23), padded to length of 2
    public const string IGNORED            = '%' ; // ignored
    public const string ISO8601            = '%z' ; // ISO date (0000-00-00T00:00:00.000Z)
    public const string ISO_WEEK           = '%k' ; // ISO week number of year (1..53)
    public const string ISO_WEEK_PADDED    = '%kk' ; // ISO week number of year (01..53), padded to length of 2
    public const string LITERAL            = '%%' ; // literal %
    public const string MINUTE             = '%i' ; // minute (0..59)
    public const string MINUTE_PADDED      = '%ii' ; // minute (00..59), padded to length of 2
    public const string MONTH              = '%m' ; // month (1..12)
    public const string MONTH_NAME         = '%mmmm' ; // English name of month (January..December)
    public const string MONTH_NAME_SHORT   = '%mmm' ; // abbreviated English name of month (Jan..Dec)
    public const string MONTH_PADDED       = '%mm' ; // month (01..12), padded to length of 2
    public const string SECOND             = '%s' ; // second (0..59)
    public const string SECOND_PADDED      = '%ss' ; // second (00..59), padded to length of 2
    public const string YEAR               = '%y' ; // year (0..9999)
    public const string YEAR_SHORT         = '%yy' ; // year (00..99), abbreviated (last two digits)
    public const string YEAR_PADDED        = '%yyyy' ; // year (0000..9999), padded to length of 4
    public const string YEAR_PREFIXED      = '%yyyyyy' ; // year (-009999 .. +009999), with sign prefix and padded to length of 6
    public const string MILLISECOND        = '%f' ; // millisecond (0..999)
    public const string MILLISECOND_PADDED = '%fff' ; // millisecond (000..999), padded to length of 3
    public const string LEAP_YEAR          = '%l' ; // leap year (0 or 1)
    public const string QUARTER            = '%q' ; // quarter (1..4)
    public const string WEEK_DAY_SHORT     = '%www' ; // abbreviated English name of weekday (Sun..Sat)
    public const string WEEK_DAY           = '%wwww' ; // English name of weekday (Sunday..Saturday)
    public const string SPECIAL_ESCAPE     = '%&' ; // special escape sequence for rare occasions
}
