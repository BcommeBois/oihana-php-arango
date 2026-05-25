<?php

namespace oihana\arango\db\functions\dates;

/**
 * Return the date corresponding to "tomorrow" based on the given date or the current date.
 *
 * Constructs an AQL expression equivalent to:
 * ```aql
 * DATE_ADD(date, 1, "day")
 * ```
 * If no date is provided, the current date (from `DATE_NOW()`) is used as the reference point.
 *
 * @param int|string|null $date Numeric timestamp or ISO 8601 date string. Defaults to `DATE_NOW()` if null.
 *
 * @return string The AQL expression returning the ISO 8601 string for the date of tomorrow.
 *
 * @example
 * ```php
 * echo tomorrow('2025-10-08T00:00:00Z');
 * // Produces: DATE_ADD("2025-10-08T00:00:00Z", 1, "day")
 *
 * echo tomorrow();
 * // Produces: DATE_ADD(DATE_NOW(), 1, "day")
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_add
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function tomorrow( null|string|int $date = null ) :string
{
    return dateAdd( $date , 1 ) ;
}
