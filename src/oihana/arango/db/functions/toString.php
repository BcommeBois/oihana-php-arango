<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CastingFunction;
use function oihana\core\strings\func;

/**
 * Converts a value of any type into a string.
 *
 * This helper wraps the ArangoDB AQL function `TO_STRING()`, which casts
 * the provided value to a string. Conversion rules follow AQL semantics:
 * - Numbers are converted to their string representation.
 * - Boolean `true` becomes `'true'`, `false` becomes `'false'`.
 * - `null` is converted to an empty string `''`.
 *
 * Example AQL usage:
 * ```aql
 * TO_STRING(42)        // returns "42"
 * TO_STRING(doc.name)  // converts the doc.name field to a string
 * TO_STRING(true)      // returns "true"
 * TO_STRING(null)      // returns ""
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\toString;
 *
 * $expr = toString('doc.age');
 * // Produces: 'TO_STRING(doc.age)'
 * ```
 *
 * @param mixed $value The AQL field or expression to convert to a string.
 * @return string The formatted AQL expression (e.g., `'TO_STRING(doc.age)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#to_string
 * @package oihana\arango\db\functions
 * @since 1.0.0
 * author Marc Alcaraz
 */
function toString( mixed $value ) :string
{
    return func( CastingFunction::TO_STRING , $value ) ;
}