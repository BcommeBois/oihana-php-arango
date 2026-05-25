<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the timezone of a date value.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_TIMEZONE()
 * ```
 *
 * @return string AQL expression returning the timezone string of a date.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_timezone
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateTimezone() :string
{
    return func( DateFunction::DATE_TIMEZONE ) ;
}
