<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Returns the name of the current database.
 *
 * The current database is the database name that was specified in the URL path of the request (or defaults to _system database).
 *
 * Returns databaseName (string): the current database name
 *
 * This helper wraps the ArangoDB AQL function `CURRENT_DATABASE()`.
 *
 * Example AQL output:
 * ```aql
 * CURRENT_DATABASE()
 * ```
 *
 * @return string The formatted AQL expression (e.g. `'CURRENT_DATABASE()'`).
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\collectionCount;
 *
 * $expr = currentDatabase();
 * // Produces: 'CURRENT_DATABASE()'
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#current_database
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author   Marc Alcaraz
 */
function currentDatabase() :string
{
    return func( MiscFunction::CURRENT_DATABASE ) ;
}
