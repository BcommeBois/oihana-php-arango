<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\DateUnit;
use oihana\arango\db\enums\functions\DateFunction;

use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\func;

/**
 * Check if two partial dates match.
 * DATE_COMPARE(date1, date2, unitRangeStart, unitRangeEnd) → bool
 * returns bool (bool): true if the dates match, false otherwise
 * All components of date1 and date2 as specified by the range will be compared.
 * You can refer to the units as:
 * - y, year, years
 * - m, month, months
 * - d, day, days
 * - h, hour, hours
 * - i, minute, minutes
 * - s, second, seconds
 * - f, millisecond, milliseconds
 * @param string|int|null $date1 - numeric timestamp or ISO 8601 date time string
 * @param string|int|null $date2 - numeric timestamp or ISO 8601 date time string
 * @param string|null $unitRangeStart - unit to start from, see below
 * @param string|null $unitRangeEnd - unit to end with, leave out to only compare the component as specified by unitRangeStart. An error is raised if unitRangeEnd is a unit before unitRangeStart.
 * @return string
 */
function dateCompare
(
    null|string|int $date1          = null ,
    null|string|int $date2          = null ,
    null|string     $unitRangeStart = 'y'  ,
    null|string     $unitRangeEnd   = null
)
:string
{
    if( is_null( $date1 ) )
    {
        $date1 = dateNow() ;
    }

    if( is_null( $date2 ) )
    {
        $date2 = dateNow() ;
    }

    return func( DateFunction::DATE_COMPARE ,
    [
        $date1 ,
        $date2 ,
        DateUnit::includes( $unitRangeStart ) ? betweenDoubleQuotes( $unitRangeStart ) : null ,
        DateUnit::includes( $unitRangeEnd   ) ? betweenDoubleQuotes( $unitRangeEnd   ) : null ,
    ]) ;
}