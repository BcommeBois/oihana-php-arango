<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the day number within the year for a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_DAYOFYEAR(date) → dayOfYear
 * ```
 * Returns a number from 1 to 365 (or 366 for leap years).
 *
 * @param string|int|null $date A numeric timestamp or ISO 8601 date string. If null, uses `dateNow()`.
 *
 * @return string The AQL expression returning the day of year.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_dayofyear
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateDayOfYear( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_DAYOFYEAR , $date ) ;
}