<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CastingFunction;
use function oihana\core\strings\func;

/**
 * Converts a value of any type into a numeric value.
 *
 * This helper wraps the ArangoDB AQL function `TO_NUMBER()`, which casts
 * the provided value to a number. Conversion rules follow AQL semantics:
 * - Strings containing numeric representations are converted to numbers.
 * - Boolean `true` becomes 1, `false` becomes 0.
 * - `null` is converted to 0.
 *
 * Example AQL usage:
 * ```aql
 * TO_NUMBER("42")       // returns 42
 * TO_NUMBER(doc.count)  // converts the doc.count field to a number
 * TO_NUMBER(true)       // returns 1
 * TO_NUMBER(null)       // returns 0
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\toNumber;
 *
 * $expr = toNumber('doc.age');
 * // Produces: 'TO_NUMBER(doc.age)'
 * ```
 *
 * @param mixed $value The AQL field or expression to convert to a number.
 * @return string The formatted AQL expression (e.g., `'TO_NUMBER(doc.age)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#to_number
 * @package oihana\arango\db\functions
 * @since 1.0.0
 * author Marc Alcaraz
 */
function toNumber( mixed $value ) :string
{
    return func( CastingFunction::TO_NUMBER , $value ) ;
}