<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the ISO week number of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_ISOWEEK(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 * The result is a number representing the ISO week of the year (1–53).
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the ISO week number.
 *
 * @example
 * ```php
 * echo dateIsoWeek('2025-10-08T12:34:56Z');
 * // Produces: DATE_ISOWEEK("2025-10-08T12:34:56Z")
 *
 * echo dateIsoWeek();
 * // Produces: DATE_ISOWEEK(DATE_NOW())
 * ```
 *
 * @see https://www.arangodb.com/docs/stable/aql/functions-date.html#date_isoweek
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateIsoWeek( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_ISOWEEK , $date ) ;
}
