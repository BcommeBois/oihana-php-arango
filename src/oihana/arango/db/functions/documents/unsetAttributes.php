<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Remove the given top-level attributes from a document.
 *
 * Wraps the ArangoDB AQL function `UNSET(document, attributeName1, … attributeNameN)`.
 * The attribute names are emitted as quoted string literals (`json_encode`); the document
 * stays a raw expression.
 *
 * > Named `unsetAttributes()` rather than `unset()` because `unset` is a reserved PHP keyword.
 *
 * Example AQL usage:
 * ```aql
 * UNSET(doc, "_id", "_rev")   // a copy of doc without its _id and _rev attributes
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\unsetAttributes;
 *
 * $expr = unsetAttributes('doc', '_id', '_rev');
 * // Produces: 'UNSET(doc,"_id","_rev")'
 * ```
 *
 * @param string $document      The document variable or expression.
 * @param string ...$attributes The attribute names to remove.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#unset
 * @see unsetRecursive()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function unsetAttributes( string $document , string ...$attributes ) : string
{
    $args = array_merge( [ $document ] , array_map( fn( $a ) => json_encode( $a ) , $attributes ) ) ;
    return func( DocumentFunction::UNSET , $args ) ;
}
