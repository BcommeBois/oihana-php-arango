<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression that checks whether a value is a string.
 *
 * This helper wraps the ArangoDB AQL function `IS_STRING()`, which returns
 * `true` if the provided value is a string, and `false` otherwise.
 *
 * Example AQL output:
 * ```aql
 * IS_STRING(doc.title)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\isString;
 *
 * $expr = isString('doc.title');
 * // Produces: 'IS_STRING(doc.title)'
 * ```
 *
 * @param string $value The AQL field or expression to check (e.g., `'doc.title'`).
 * @return string The formatted AQL expression (e.g., `'IS_STRING(doc.title)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#is_string
 * @package oihana\arango\db\functions
 * @since 1.0.0
 * author Marc Alcaraz
 */
function isString( mixed $value ) :string
{
    return func( CheckFunction::IS_STRING , $value ) ;
}
