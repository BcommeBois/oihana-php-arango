<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Test whether a document identifier belongs to a given collection.
 *
 * Wraps the ArangoDB AQL function `IS_SAME_COLLECTION(collectionName, documentIdentifier)`.
 * The collection name is emitted as a quoted string literal (`json_encode`); the document
 * identifier stays a raw expression (typically `doc._id`).
 *
 * Example AQL usage:
 * ```aql
 * IS_SAME_COLLECTION("products", "products/123")   // returns true
 * IS_SAME_COLLECTION("products", doc._id)          // true if doc lives in products
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\isSameCollection;
 *
 * $expr = isSameCollection('products', 'doc._id');
 * // Produces: 'IS_SAME_COLLECTION("products",doc._id)'
 * ```
 *
 * @param string $collectionName     The collection name (emitted as a quoted string literal).
 * @param string $documentIdentifier A document handle / `_id` expression or string literal.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#is_same_collection
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function isSameCollection( string $collectionName , string $documentIdentifier ) : string
{
    return func( DocumentFunction::IS_SAME_COLLECTION , [ json_encode( $collectionName ) , $documentIdentifier ] ) ;
}
