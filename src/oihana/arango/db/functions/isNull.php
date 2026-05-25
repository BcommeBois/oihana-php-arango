<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression that checks whether a value is `null`.
 *
 * This helper wraps the ArangoDB AQL function `IS_NULL()`, which returns
 * `true` if the given value is `null`, and `false` otherwise.
 *
 * Example AQL output:
 * ```aql
 * IS_NULL(user.email)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\isNull;
 *
 * $expr = isNull('user.email');
 * // Produces: 'IS_NULL(user.email)'
 * ```
 *
 * @param string $value The AQL field or expression to check (e.g. `'user.email'`).
 * @return string The formatted AQL expression (e.g. `'IS_NULL(user.email)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#is_null
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function isNull( mixed $value ) :string
{
    return func( CheckFunction::IS_NULL , $value ) ;
}
