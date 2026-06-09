<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the key part of a document identifier.
 *
 * Wraps the ArangoDB AQL function `PARSE_KEY(documentIdentifier)`.
 *
 * Example AQL usage:
 * ```aql
 * PARSE_KEY("products/123")   // returns "123"
 * PARSE_KEY(doc._id)          // key of the current document
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\parseKey;
 *
 * $expr = parseKey('doc._id');
 * // Produces: 'PARSE_KEY(doc._id)'
 * ```
 *
 * @param string $documentIdentifier A document handle / `_id` expression or string literal.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#parse_key
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function parseKey( string $documentIdentifier ) : string
{
    return func( DocumentFunction::PARSE_KEY , $documentIdentifier ) ;
}
