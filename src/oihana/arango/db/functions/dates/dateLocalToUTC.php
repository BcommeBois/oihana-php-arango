<?php

namespace oihana\arango\db\functions\dates;

use InvalidArgumentException;
use oihana\arango\db\enums\functions\DateFunction;
use oihana\enums\Char;

use function oihana\core\date\isValidTimezone;
use function oihana\core\strings\func;

/**
 * Converts a local date/time from a specified timezone to UTC.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_LOCALTOUTC(date, timezone)
 * ```
 * where `date` can be a numeric timestamp or ISO 8601 string, and `timezone` is a valid IANA timezone name.
 *
 * If `$date` is null, the current date/time (`DATE_NOW()`) is used.
 *
 * By default, throws an `InvalidArgumentException` if the `$timezone` is not a valid IANA timezone.
 *
 * @param int|string|null $date      Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 * @param string          $timezone  IANA timezone name (e.g., "Europe/Paris", "America/New_York").
 * @param bool            $throwable Whether to throw an exception on invalid timezone (default: true).
 *
 * @return string The AQL expression returning the date converted to UTC.
 *
 * @example
 * ```php
 * echo dateLocalToUTC('2025-10-08T12:34:56', 'Europe/Paris');
 * // Produces: DATE_LOCALTOUTC("2025-10-08T12:34:56", "Europe/Paris")
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_localtoutc
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateLocalToUTC
(
    null|string|int $date      = null ,
    string          $timezone  = "UTC" ,
    bool            $throwable = true
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

    return func( DateFunction::DATE_LOCALTOUTC , [ $date , $timezone ] ) ;
}
