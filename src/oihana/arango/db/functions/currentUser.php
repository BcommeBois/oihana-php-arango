<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Return the name of the current user.
 *
 * TThe current user is the user account name that was specified in the Authorization HTTP header of the request.
 * It will only be populated if authentication on the server is turned on, and if the query
 * was executed inside a request context. Otherwise, the return value of this function will be null.
 *
 * Returns userName (string|null): the current user name, or null if authentication is disabled
 *
 * This helper wraps the ArangoDB AQL function `CURRENT_USER()`.
 *
 * Example AQL output:
 * ```aql
 * CURRENT_USER()
 * ```
 *
 * @return string The formatted AQL expression (e.g. `'CURRENT_USER()'`).
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\currentUser;
 *
 * $expr = currentUser();
 * // Produces: 'CURRENT_USER()'
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#current_user
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author   Marc Alcaraz
 */
function currentUser() :string
{
    return func( MiscFunction::CURRENT_USER ) ;
}
