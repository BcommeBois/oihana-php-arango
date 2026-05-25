<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the month (1–12) of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_MONTH(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the month as a number (1–12).
 *
 * @example
 * ```php
 * echo dateMonth('2025-10-08T12:34:56Z');
 * // Produces: DATE_MONTH("2025-10-08T12:34:56Z")
 *
 * echo dateMonth();
 * // Produces: DATE_MONTH(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/devel/aql/functions/date/#date_month
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateMonth( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_MONTH , $date ) ;
}
