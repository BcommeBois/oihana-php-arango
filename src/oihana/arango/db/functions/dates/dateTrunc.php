<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\DateUnit;
use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Truncates the given date to the specified unit and returns the modified ISO 8601 date string.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_TRUNC(date, unit)
 * ```
 * where `date` can be a numeric timestamp or ISO 8601 string, and `unit` specifies
 * the precision to truncate to.
 *
 * Supported units (case-insensitive):
 * - y, year, years
 * - m, month, months (default)
 * - d, day, days
 * - h, hour, hours
 * - i, minute, minutes
 * - s, second, seconds
 * - f, millisecond, milliseconds
 *
 * @param string|int|null $date The date to truncate. If null, the current timestamp (`DATE_NOW()`) is used.
 * @param string|null $unit The time unit to truncate to (default: `"month"`).
 *
 * @return string The AQL expression returning the truncated ISO 8601 date string.
 *
 * @example
 * ```php
 * echo dateTrunc('2025-10-08T12:34:56Z', 'day');
 * // Produces: DATE_TRUNC("2025-10-08T12:34:56Z", "day")
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_trunc
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateTrunc( null|string|int $date = null , ?string $unit = DateUnit::MONTH ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_TRUNC , [ $date , timeUnit($unit) ] ) ;
}