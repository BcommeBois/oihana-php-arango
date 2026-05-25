<?php

namespace oihana\arango\db\functions\dates;

use InvalidArgumentException;
use oihana\arango\db\enums\functions\DateFunction;
use oihana\enums\Char;

use function oihana\core\date\isValidTimezone;
use function oihana\core\strings\func;

/**
 * Converts a UTC (Zulu time) date or timestamp into a local timezone date.
 *
 * Constructs an AQL expression in the form:
 * ```aql
 * DATE_UTCTOLOCAL(date, timezone, zoneinfo)
 * ```
 * Converts a UTC timestamp or ISO 8601 string into the specified local time zone.
 *
 * If no date is provided, the current time (`DATE_NOW()`) is used.
 *
 * By default, throws an `InvalidArgumentException` if the `$timezone` is not a valid IANA timezone.
 *
 * @param string|int|null $date      A numeric timestamp or ISO 8601 date-time string. Defaults to `DATE_NOW()` if null.
 * @param string          $timezone  IANA timezone name (e.g., `"America/New_York"`, `"Europe/Berlin"`, `"UTC"`).
 *                                   Use `"America/Los_Angeles"` for Pacific Time (PST/PDT).
 * @param bool            $throwable Whether to throw an exception on invalid timezone (default: true).
 *
 * @return string The AQL expression returning the date converted from UTC to the specified local time zone.
 *
 * @throws InvalidArgumentException If the timezone is invalid.
 *
 * @example
 * ```php
 * echo dateUTCToLocal("2025-10-08T12:00:00Z", "Europe/Paris");
 * // Produces: DATE_UTCTOLOCAL("2025-10-08T12:00:00Z", "Europe/Paris")
 *
 * echo dateUTCToLocal(null, "America/New_York");
 * // Produces: DATE_UTCTOLOCAL(DATE_NOW(), "America/New_York")
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_utctolocal
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateUTCToLocal
(
    null|string|int $date      = null ,
    string          $timezone  = "Europe/Paris" ,
    bool            $throwable = false
)
:string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }

    if ( $throwable && !isValidTimezone( trim( $timezone , Char::DOUBLE_QUOTE ) ) )
    {
        throw new InvalidArgumentException( sprintf('Invalid timezone %s.' , $timezone ) ) ;
    }

    return func( DateFunction::DATE_UTCTOLOCAL , [ $date , $timezone ] ) ;
}
