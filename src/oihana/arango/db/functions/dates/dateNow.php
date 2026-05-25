<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\functions\DateFunction;
use function oihana\core\strings\func;

/**
 * Returns the current Unix timestamp (milliseconds since epoch).
 *
 * This helper builds the AQL expression:
 * ```aql
 * DATE_NOW()
 * ```
 *
 * It is equivalent to calling `DATE_NOW()` in AQL, which returns the
 * current UTC timestamp as a numeric value representing milliseconds since
 * January 1, 1970 (Unix epoch).
 *
 * @return string The AQL expression string `"DATE_NOW()"`.
 *
 * @example
 * ```php
 * echo dateNow();
 * // Produces: "DATE_NOW()"
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_now
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function dateNow() :string
{
    return func( DateFunction::DATE_NOW ) ;
}
