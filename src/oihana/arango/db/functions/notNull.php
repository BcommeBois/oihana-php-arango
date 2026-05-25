<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression return the first element that is not null,
 * and null if all alternatives are null themselves.
 *
 * It is also known as COALESCE() in SQL.
 *
 * This helper wraps the ArangoDB AQL function `NOT_NULL()`.
 *
 * Example AQL output:
 * ```aql
 * RETURN NOT_NULL(null, null, "foo", "bar") // "foo"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\notNull;
 *
 * $expr = notNull('user.email');
 * // Produces: 'NOT_NULL(user.email)'
 * ```
 *
 * @param mixed ...$alternative input of arbitrary type
 *
 * @return string The formatted AQL expression (e.g. `'NOT_NULL(user.email)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#not_null
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function notNull( mixed ...$alternative ) :string
{
    return func( MiscFunction::NOT_NULL , $alternative ) ;
}
