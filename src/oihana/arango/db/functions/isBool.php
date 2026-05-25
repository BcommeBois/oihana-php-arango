<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression that checks whether a given value is a boolean.
 *
 * This helper wraps the ArangoDB AQL function `IS_BOOL()` and returns a string
 * representation of that expression.
 *
 * The AQL function `IS_BOOL(value)` returns `true` if the provided value is a
 * boolean (`true` or `false`), and `false` otherwise.
 *
 * Example output:
 * ```aql
 * IS_BOOL(doc.isActive)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\isBool;
 *
 * $expr = isBool('doc.isActive');
 * // Produces: 'IS_BOOL(doc.isActive)'
 * ```
 *
 * @param string $value The AQL field or expression to check (e.g. `'doc.isActive'`).
 * @return string The AQL expression as a string (e.g. `'IS_BOOL(doc.isActive)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#is_datestring
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isBool( mixed $value ) :string
{
    return func( CheckFunction::IS_BOOL , $value ) ;
}