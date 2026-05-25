<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CastingFunction;
use function oihana\core\strings\func;

/**
 * Converts a value of any type into a boolean.
 *
 * This helper wraps the ArangoDB AQL function `TO_BOOL()`, which casts
 * the provided value to a boolean. The conversion rules follow AQL semantics:
 * - Non-zero numbers and non-empty strings evaluate to `true`.
 * - Zero, empty strings, `null`, and empty arrays evaluate to `false`.
 *
 * Example AQL usage:
 * ```aql
 * TO_BOOL(doc.isActive)   // converts doc.isActive to boolean
 * TO_BOOL("")             // returns false
 * TO_BOOL(1)              // returns true
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\toBool;
 *
 * $expr = toBool('doc.isActive');
 * // Produces: 'TO_BOOL(doc.isActive)'
 * ```
 *
 * @param mixed $value The AQL field or expression to convert to boolean.
 * @return string The formatted AQL expression (e.g., `'TO_BOOL(doc.isActive)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#to_bool
 * @package oihana\arango\db\functions
 * @since 1.0.0
 * author Marc Alcaraz
 */
function toBool( mixed $value ) :string
{
    return func( CastingFunction::TO_BOOL , $value ) ;
}
