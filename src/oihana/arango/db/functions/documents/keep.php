<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Keep only the given top-level attributes of a document.
 *
 * Wraps the ArangoDB AQL function `KEEP(document, attributeName1, … attributeNameN)`.
 * The attribute names are emitted as quoted string literals (`json_encode`), so AQL
 * receives a valid call; the document stays a raw expression.
 *
 * Example AQL usage:
 * ```aql
 * KEEP(doc, "name", "age")   // a copy of doc with only its name and age attributes
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\keep;
 *
 * $expr = keep('doc', 'name', 'age');
 * // Produces: 'KEEP(doc,"name","age")'
 * ```
 *
 * @param string $document      The document variable or expression.
 * @param string ...$attributes The attribute names to keep.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#keep
 * @see keepRecursive()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function keep( string $document , string ...$attributes ) : string
{
    $args = array_merge( [ $document ] , array_map( fn( $a ) => json_encode( $a ) , $attributes ) ) ;
    return func( DocumentFunction::KEEP , $args ) ;
}
