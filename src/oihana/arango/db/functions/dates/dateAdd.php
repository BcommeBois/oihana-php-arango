<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\DateUnit;
use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\func;

/**
 * Adds a specified amount of time to a given date and returns the resulting ISO 8601 date string.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_ADD(date, amount, unit)
 * ```
 * where `date` can be a numeric timestamp or ISO 8601 string, `amount` is the number
 * of units to add (or subtract if negative), and `unit` specifies the time unit.
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
 * @param string|int|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 * @param string|int $amount Number of units to add (positive) or subtract (negative). Positive values recommended.
 * @param string $unit Time unit to add (default: `"day"`).
 *
 * @return string The AQL expression returning the calculated ISO 8601 date string.
 *
 * @example
 * ```php
 * echo dateAdd('2025-10-08T12:00:00Z', 3, 'day');
 * // Produces: DATE_ADD("2025-10-08T12:00:00Z", 3, "day")
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_add
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateAdd( null|string|int $date , string|int $amount , string $unit = DateUnit::DAY ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func
    (
        DateFunction::DATE_ADD ,
        [
            $date ,
            betweenDoubleQuotes( $amount , useQuotes: is_string( $amount ) ) ,
            timeUnit( $unit )
        ]
    ) ;
}
