<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the attributes of a document as an array of key/value pairs.
 *
 * Wraps the ArangoDB AQL function `ENTRIES(document)`.
 *
 * Example AQL usage:
 * ```aql
 * ENTRIES({a: 1, b: 2})   // returns [["a", 1], ["b", 2]]
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\entries;
 *
 * $expr = entries('doc');
 * // Produces: 'ENTRIES(doc)'
 * ```
 *
 * @param string $document The document variable or expression.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#entries
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function entries( string $document ) : string
{
    return func( DocumentFunction::ENTRIES , $document ) ;
}
