<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression that checks whether a value is a valid date string.
 *
 * This helper wraps the ArangoDB AQL function `IS_DATESTRING()`, which tests if
 * the given value is a string that can be used in a date function.
 *
 * The function returns `true` for properly formatted date strings (e.g. `"2015"`, `"2015-10"`, `"2015-10-07T15:32:10Z"`)
 * even if the actual date value is invalid (e.g. `"2015-02-31"`).
 *
 * Example AQL output:
 * ```aql
 * IS_DATESTRING(doc.createdAt)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\isDateString;
 *
 * $expr = isDateString('doc.createdAt');
 * // Produces: 'IS_DATESTRING(doc.createdAt)'
 * ```
 *
 * @param string $value The AQL field or expression to check (e.g. `'doc.createdAt'`).
 * @return string The AQL expression as a string (e.g. `'IS_DATESTRING(doc.createdAt)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#is_datestring
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isDateString( mixed $value ) :string
{
    return func( CheckFunction::IS_DATESTRING , $value ) ;
}
