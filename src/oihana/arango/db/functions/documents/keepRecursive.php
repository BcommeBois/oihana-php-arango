<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Keep only the given attributes of a document, recursing into sub-documents.
 *
 * Wraps the ArangoDB AQL function `KEEP_RECURSIVE(document, attributeName1, … attributeNameN)`.
 * The attribute names are emitted as quoted string literals (`json_encode`); the document
 * stays a raw expression.
 *
 * Example AQL usage:
 * ```aql
 * KEEP_RECURSIVE(doc, "name", "meta")   // keeps name/meta at every nesting level
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\keepRecursive;
 *
 * $expr = keepRecursive('doc', 'name', 'meta');
 * // Produces: 'KEEP_RECURSIVE(doc,"name","meta")'
 * ```
 *
 * @param string $document      The document variable or expression.
 * @param string ...$attributes The attribute names to keep at every level.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#keep_recursive
 * @see keep()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function keepRecursive( string $document , string ...$attributes ) : string
{
    $args = array_merge( [ $document ] , array_map( fn( $a ) => json_encode( $a ) , $attributes ) ) ;
    return func( DocumentFunction::KEEP_RECURSIVE , $args ) ;
}
