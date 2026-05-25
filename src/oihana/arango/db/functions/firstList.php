<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Return the first alternative that is an array, and null if none of the alternatives is an array.
 *
 * This helper wraps the ArangoDB AQL function `FIRST_LIST()`.
 *
 * Example AQL output:
 * ```aql
 * FIRST_LIST(null, null, ["foo"], "bar") // ["foo"]
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\firstList;
 *
 * $expr = firstList('null,doc,["hello"]);
 * // Produces: 'FIRST_LIST(null,doc,["hello"])'
 * ```
 *
 * @param mixed ...$alternative input of arbitrary type
 *
 * @return string The formatted AQL expression (e.g. `'FIRST_LIST(alternative,....)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#first_list
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function firstList( mixed ...$alternative ) :string
{
    return func( MiscFunction::FIRST_LIST , $alternative ) ;
}
