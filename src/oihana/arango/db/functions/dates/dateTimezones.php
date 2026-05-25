<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns a list of all valid timezone names known to ArangoDB.
 *
 * Constructs an AQL expression in the format:
 * ```aql
 * DATE_TIMEZONES()
 * ```
 *
 * @return string AQL expression returning an array of timezone strings.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_timezones
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateTimezones() :string
{
    return func( DateFunction::DATE_TIMEZONES ) ;
}
