<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns whether the year of a given date is a leap year.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_LEAPYEAR(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 * The result is a boolean: `true` if it is a leap year, `false` otherwise.
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning a boolean indicating leap year.
 *
 * @example
 * ```php
 * echo dateLeapYear('2024-02-29T12:00:00Z');
 * // Produces: DATE_LEAPYEAR("2024-02-29T12:00:00Z")
 *
 * echo dateLeapYear();
 * // Produces: DATE_LEAPYEAR(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/devel/aql/functions/date/#date_leapyear
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateLeapYear( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_LEAPYEAR , $date ) ;
}
