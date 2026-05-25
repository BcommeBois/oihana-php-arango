<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the day of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_DAY(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 * The result is a number representing the day of the month (1–31).
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the day as a number.
 *
 * @example
 * ```php
 * echo dateDay('2025-10-08T12:00:00Z');
 * // Produces: DATE_DAY("2025-10-08T12:00:00Z")
 *
 * echo dateDay();
 * // Produces: DATE_DAY(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/devel/aql/functions/date/#date_day
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateDay( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_DAY , $date ) ;
}
