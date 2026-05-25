<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the hour of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_HOUR(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 * The result is a number representing the hour (0–23).
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the hour as a number.
 *
 * @example
 * ```php
 * echo dateHour('2025-10-08T12:34:56Z');
 * // Produces: DATE_HOUR("2025-10-08T12:34:56Z")
 *
 * echo dateHour();
 * // Produces: DATE_HOUR(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/devel/aql/functions/date/#date_hour
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateHour( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_HOUR , $date ) ;
}
