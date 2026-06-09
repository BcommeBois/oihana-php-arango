<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the collection name part of a document identifier.
 *
 * Wraps the ArangoDB AQL function `PARSE_COLLECTION(documentIdentifier)`.
 *
 * Example AQL usage:
 * ```aql
 * PARSE_COLLECTION("products/123")   // returns "products"
 * PARSE_COLLECTION(doc._id)          // collection of the current document
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\parseCollection;
 *
 * $expr = parseCollection('doc._id');
 * // Produces: 'PARSE_COLLECTION(doc._id)'
 * ```
 *
 * @param string $documentIdentifier A document handle / `_id` expression or string literal.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#parse_collection
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function parseCollection( string $documentIdentifier ) : string
{
    return func( DocumentFunction::PARSE_COLLECTION , $documentIdentifier ) ;
}
