<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the quarter (1–4) of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_QUARTER(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the quarter as a number (1–4).
 *
 * @example
 * ```php
 * echo dateQuarter('2025-10-08T12:34:56Z');
 * // Produces: DATE_QUARTER("2025-10-08T12:34:56Z")
 *
 * echo dateQuarter();
 * // Produces: DATE_QUARTER(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/devel/aql/functions/date/#date_quarter
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateQuarter( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_QUARTER , $date ) ;
}
