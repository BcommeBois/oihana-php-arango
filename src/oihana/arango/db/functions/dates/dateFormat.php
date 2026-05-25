<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\DateFormat;
use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\func;

/**
 * Format a date according to a specified format string.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_FORMAT(date, format)
 * ```
 *
 * The `format` string supports the following placeholders (case-insensitive):
 *
 * <ul>
 * <li>%t – timestamp in milliseconds since 1970-01-01T00:00:00Z</li>
 * <li>%z – ISO date (0000-00-00T00:00:00.000Z)</li>
 * <li>%w – day of week (0..6)</li>
 * <li>%y – year (0..9999)</li>
 * <li>%yy – last two digits of year</li>
 * <li>%yyyy – padded year (0000..9999)</li>
 * <li>%yyyyyy – signed and padded year (-009999..+009999)</li>
 * <li>%m – month (1..12)</li>
 * <li>%mm – month padded to 2 digits</li>
 * <li>%d – day (1..31)</li>
 * <li>%dd – day padded to 2 digits</li>
 * <li>%h – hour (0..23)</li>
 * <li>%hh – hour padded to 2 digits</li>
 * <li>%i – minute (0..59)</li>
 * <li>%ii – minute padded to 2 digits</li>
 * <li>%s – second (0..59)</li>
 * <li>%ss – second padded to 2 digits</li>
 * <li>%f – millisecond (0..999)</li>
 * <li>%fff – millisecond padded to 3 digits</li>
 * <li>%x – day of year (1..366)</li>
 * <li>%xxx – day of year padded to 3 digits</li>
 * <li>%k – ISO week number (1..53)</li>
 * <li>%kk – ISO week number padded to 2 digits</li>
 * <li>%l – leap year (0 or 1)</li>
 * <li>%q – quarter (1..4)</li>
 * <li>%a – days in month (28..31)</li>
 * <li>%mmm – abbreviated English month name (Jan..Dec)</li>
 * <li>%mmmm – full English month name (January..December)</li>
 * <li>%www – abbreviated English weekday name (Sun..Sat)</li>
 * <li>%wwww – full English weekday name (Sunday..Saturday)</li>
 * <li>%& – special escape sequence</li>
 * <li>%% – literal %</li>
 * <li>% – ignored</li>
 * </ul>
 *
 * @param null|string|int $date      A numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 * @param string          $format    Format string using placeholders (default: `DateFormat::ISO8601`).
 * @param bool            $useQuotes Whether to wrap the expression (default: true).
 *
 * @return string AQL expression returning the formatted date string.
 *
 * @example
 * ```php
 * dateFormat(dateNow(), "%q/%yyyy"); // "3/2015" (quarter/year)
 * dateFormat(dateNow(), "%dd.%mm.%yyyy %hh:%ii:%ss,%fff"); // "18.09.2015 15:30:49,374"
 * dateFormat("1969", "Summer of '%yy"); // "Summer of '69"
 * dateFormat("2016", "%%l = %l"); // "%l = 1" (leap year)
 * dateFormat("2016-03-01", "%xxx%"); // "063", trailing % ignored
 * ```
 *
 * @see DateFormat
 * @see https://docs.arangodb.com/3.11/aql/functions/date/#date_format
 */
function dateFormat
(
    null|string|int $date      = null ,
    string          $format    = DateFormat::ISO8601 ,
    bool            $useQuotes = true ,
)
:string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }

    return func( DateFunction::DATE_FORMAT , [ $date , betweenDoubleQuotes( $format , useQuotes: $useQuotes ) ] ) ;
}