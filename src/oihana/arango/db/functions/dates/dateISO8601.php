<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Converts a given date to an ISO 8601 formatted string.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_ISO8601(date)
 * ```
 * where `date` can be a numeric timestamp or an ISO 8601 date-time string.
 *
 * The return value is a string in ISO 8601 format.
 *
 * @param int|string|null $date A numeric timestamp or ISO 8601 date string. If null, uses `DATE_NOW()`.
 *
 * @return string The AQL expression returning the ISO 8601 date string.
 *
 * @example
 * ```php
 * echo dateISO8601('2025-10-08T12:34:56Z');
 * // Produces: DATE_ISO8601("2025-10-08T12:34:56Z")
 *
 * echo dateISO8601();
 * // Produces: DATE_ISO8601(DATE_NOW())
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_iso8601
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateISO8601( null|int|string $date = null ) :string
{
    if( is_null( $date ) )
    {
        $date = dateNow() ;
    }
    return func(DateFunction::DATE_ISO8601 , $date ) ;
}
