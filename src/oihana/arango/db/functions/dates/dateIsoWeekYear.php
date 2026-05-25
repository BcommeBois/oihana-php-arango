<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the ISO week-numbering year of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_ISOWEEKYEAR(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the ISO week-numbering year.
 *
 * @example
 * ```php
 * echo dateIsoWeekYear('2025-10-08T12:34:56Z');
 * // Produces: DATE_ISOWEEKYEAR("2025-10-08T12:34:56Z")
 *
 * echo dateIsoWeekYear();
 * // Produces: DATE_ISOWEEKYEAR(DATE_NOW())
 * ```
 *
 * @see https://www.arangodb.com/docs/stable/aql/functions-date.html#date_isoweekyear
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateIsoWeekYear( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_ISOWEEKYEAR , $date ) ;
}
