<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the weekday number of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_DAYOFWEEK(date) → dayOfWeek
 * ```
 * Returns a number between 0 and 6:
 * - 0 – Sunday
 * - 1 – Monday
 * - 2 – Tuesday
 * - 3 – Wednesday
 * - 4 – Thursday
 * - 5 – Friday
 * - 6 – Saturday
 *
 * @param string|int|null $date A numeric timestamp or ISO 8601 date string. If null, uses `dateNow()`.
 *
 * @return string The AQL expression returning the weekday number.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_dayofweek
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateDayOfWeek( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_DAYOFWEEK , $date ) ;
}