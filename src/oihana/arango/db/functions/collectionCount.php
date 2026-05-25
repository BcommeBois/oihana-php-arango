<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Determine the amount of documents in a collection. LENGTH() is preferred.
 *
 * This helper wraps the ArangoDB AQL function `COLLECTION_COUNT()`.
 *
 * Example AQL output:
 * ```aql
 * COLLECTION_COUNT(coll)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\collectionCount;
 *
 * $expr = collectionCount('coll');
 * // Produces: 'COLLECTION_COUNT(coll)'
 * ```
 *
 * @param mixed $collection
 *
 * @return string The formatted AQL expression (e.g. `'COLLECTION_COUNT(coll)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#collection_count
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function collectionCount( mixed $collection ) :string
{
    return func( MiscFunction::COLLECTION_COUNT , $collection ) ;
}
