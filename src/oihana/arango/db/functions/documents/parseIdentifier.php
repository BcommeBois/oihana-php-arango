<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the collection name and key of a document identifier as an object.
 *
 * Wraps the ArangoDB AQL function `PARSE_IDENTIFIER(documentIdentifier)`, which
 * returns `{ collection, key }`.
 *
 * Example AQL usage:
 * ```aql
 * PARSE_IDENTIFIER("products/123")   // returns {collection: "products", key: "123"}
 * PARSE_IDENTIFIER(doc._id)          // parts of the current document handle
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\parseIdentifier;
 *
 * $expr = parseIdentifier('doc._id');
 * // Produces: 'PARSE_IDENTIFIER(doc._id)'
 * ```
 *
 * @param string $documentIdentifier A document handle / `_id` expression or string literal.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#parse_identifier
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function parseIdentifier( string $documentIdentifier ) : string
{
    return func( DocumentFunction::PARSE_IDENTIFIER , $documentIdentifier ) ;
}
