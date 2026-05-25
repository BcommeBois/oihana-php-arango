<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Converts a given date into a numeric timestamp with millisecond precision.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_TIMESTAMP(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date string.
 * The result is a numeric timestamp in milliseconds. To obtain seconds, divide the result by 1000.
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the timestamp.
 *
 * @example
 * ```php
 * echo dateTimeStamp('2025-10-08T12:00:00Z');
 * // Produces: DATE_TIMESTAMP("2025-10-08T12:00:00Z")
 *
 * echo dateTimeStamp();
 * // Produces: DATE_TIMESTAMP(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/devel/aql/functions/date/#date_timestamp
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateTimeStamp( null|int|string $date = null ) :string
{
    if( is_null($date) )
    {
        $date = dateNow() ;
    }
    return func(DateFunction::DATE_TIMESTAMP , $date ) ;
}