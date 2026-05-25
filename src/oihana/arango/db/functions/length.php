<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Determine the amount of documents in a collection.
 *
 * This helper wraps the ArangoDB AQL function `LENGTH()`.
 *
 * Example AQL output:
 * ```aql
 * LENGTH(coll)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\length;
 *
 * $expr = length('coll');
 * // Produces: 'LENGTH(coll)'
 * ```
 *
 * LENGTH() can also determine the number of elements in an array,
 * the number of attribute keys of an object / document and the character length of a string.
 *
 * @param mixed $collection
 *
 * @return string The formatted AQL expression (e.g. `'LENGTH(coll)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#length
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function length( mixed $collection ) :string
{
    return func( MiscFunction::LENGTH , $collection ) ;
}
