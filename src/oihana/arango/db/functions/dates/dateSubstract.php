<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\DateUnit;
use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\func;

/**
 * Subtract a specified amount of time units from a date and return the resulting date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_SUBTRACT(date, amount, unit)
 * ```
 * - `date` can be a numeric timestamp or ISO 8601 string.
 * - `amount` is the number of units to subtract (positive value) or add (negative value, though using DATE_ADD is preferred for additions).
 * - `unit` specifies the time unit.
 *
 * Supported units (case-insensitive):
 * - y, year, years
 * - m, month, months
 * - w, week, weeks
 * - d, day, days
 * - h, hour, hours
 * - i, minute, minutes
 * - s, second, seconds
 * - f, millisecond, milliseconds
 *
 * @param int|string|null $date   Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 * @param int|string      $amount Number of units to subtract (positive) or add (negative).
 * @param string          $unit   Time unit (default: `"day"`).
 *
 * @return string The AQL expression returning the calculated ISO 8601 date string.
 *
 * @example
 * ```php
 * echo dateSubtract('2025-10-08T12:00:00Z', 3, 'day');
 * // Produces: DATE_SUBTRACT("2025-10-08T12:00:00Z", 3, "day")
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_add
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateSubtract
(
    null|string|int $date ,
    string|int      $amount ,
    string $unit    = DateUnit::DAY
)
:string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func
    (
        DateFunction::DATE_SUBTRACT ,
        [
            $date ,
            betweenDoubleQuotes( $amount , useQuotes: is_string( $amount ) ) ,
            timeUnit( $unit )
        ]
    ) ;
}
