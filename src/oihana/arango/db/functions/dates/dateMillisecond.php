<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the millisecond (0–999) of a given date.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_MILLISECOND(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the millisecond.
 *
 * @example
 * ```php
 * echo dateMillisecond('2025-10-08T12:34:56.789Z');
 * // Produces: DATE_MILLISECOND("2025-10-08T12:34:56.789Z")
 *
 * echo dateMillisecond();
 * // Produces: DATE_MILLISECOND(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/devel/aql/functions/date/#date_millisecond
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateMillisecond( null|string|int $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func( DateFunction::DATE_MILLISECOND , $date ) ;
}
