<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Return the year component of a given date.
 *
 * Constructs an AQL expression equivalent to:
 * ```aql
 * DATE_YEAR(date)
 * ```
 * If no date is provided, the current date (`DATE_NOW()`) is used.
 *
 * @param string|int|null $date  A numeric timestamp or ISO 8601 date-time string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the year part of the given date as a number.
 *
 * @example
 * ```php
 * echo dateYear('2025-10-08T12:00:00Z');
 * // Produces: DATE_YEAR("2025-10-08T12:00:00Z")
 *
 * echo dateYear();
 * // Produces: DATE_YEAR(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_year
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateYear( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_YEAR , $date ) ;
}