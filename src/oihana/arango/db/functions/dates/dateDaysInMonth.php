<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the number of days in the month of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_DAYS_IN_MONTH(date) → daysInMonth
 * ```
 * Returns a number between 28 and 31 depending on the month and leap years.
 *
 * @param string|int|null $date A numeric timestamp or ISO 8601 date string. If null, uses `dateNow()`.
 * @return string The AQL expression returning the number of days in the month.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_days_in_month
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateDaysInMonth( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_DAYS_IN_MONTH , $date ) ;
}