<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Remove the given attributes from a document, recursing into sub-documents.
 *
 * Wraps the ArangoDB AQL function `UNSET_RECURSIVE(document, attributeName1, … attributeNameN)`.
 * The attribute names are emitted as quoted string literals (`json_encode`); the document
 * stays a raw expression.
 *
 * Example AQL usage:
 * ```aql
 * UNSET_RECURSIVE(doc, "_id", "_rev")   // strips _id/_rev at every nesting level
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\unsetRecursive;
 *
 * $expr = unsetRecursive('doc', '_id', '_rev');
 * // Produces: 'UNSET_RECURSIVE(doc,"_id","_rev")'
 * ```
 *
 * @param string $document      The document variable or expression.
 * @param string ...$attributes The attribute names to remove at every level.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#unset_recursive
 * @see unsetAttributes()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function unsetRecursive( string $document , string ...$attributes ) : string
{
    $args = array_merge( [ $document ] , array_map( fn( $a ) => json_encode( $a ) , $attributes ) ) ;
    return func( DocumentFunction::UNSET_RECURSIVE , $args ) ;
}
