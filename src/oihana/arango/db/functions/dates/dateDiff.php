<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\DateUnit;
use oihana\arango\db\enums\functions\DateFunction;
use oihana\enums\Boolean;

use function oihana\core\strings\func;

/**
 * Calculate the difference between two dates in given time unit, optionally with decimal places.
 * @param string|int|null $date1   numeric timestamp or ISO 8601 date time string
 * @param string|int|null $date2   numeric timestamp or ISO 8601 date time string
 * @param string          $unit    either of the following to specify the time unit to return the difference in (case-insensitive):
 *                                 - "y", "year", "years"
 *                                 - "m", "month", "months"
 *                                 - "w", "week", "weeks"
 *                                 - "d", "day", "days"
 *                                 - "h", "hour", "hours"
 *                                 - "i", "minute", "minutes"
 *                                 - "s", "second", "seconds"
 *                                 - "f", "millisecond", "milliseconds"
 * @param bool            $asFloat if set to true, decimal places will be preserved in the result. The default is false and an integer is returned.
 * @param ?string         $timezone1 if set, date1 is assumed to be in the specified timezone. If timezone2 is not set, then both date1 and date2 are assumed to be in the timezone specified by timezone1, e.g. "America/New_York", "Europe/Berlin", or "UTC". Use "America/Los_Angeles" for Pacific time (PST/PDT).
 * @param ?string         $timezone2 if set, date2 is assumed to be in the timezone specified by timezone2, and date1 is assumed to be in the timezone specified by timezone1, e.g. "America/New_York", "Europe/Berlin", or "UTC". Use "America/Los_Angeles" for Pacific time (PST/PDT).
 *
 * @return string
 */
function dateDiff
(
    null|string|int $date1     = null ,
    null|string|int $date2     = null ,
    string          $unit      = DateUnit::DAY ,
    ?bool           $asFloat   = null ,
    ?string         $timezone1 = null ,
    ?string         $timezone2 = null ,
)
:string
{
    $now = dateNow();

    $date1   ??= $now ;
    $date2   ??= $now ;
    $asFloat ??= false ;

    $arguments =
    [
        $date1 ,
        $date2 ,
        timeUnit( $unit ) ,
        $asFloat ? Boolean::TRUE : Boolean::FALSE
    ] ;

    if( isset( $timezone1 ) )
    {
        $arguments[] = $timezone1 ;
        if( isset( $timezone2 ) )
        {
            $arguments[] = $timezone2 ;
        }
    }

    return func( DateFunction::DATE_DIFF , $arguments ) ;
}
