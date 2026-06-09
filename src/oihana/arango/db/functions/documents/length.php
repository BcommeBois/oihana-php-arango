<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the number of attribute keys of a document.
 *
 * Wraps the ArangoDB AQL function `LENGTH(document)` (also aliased as `COUNT()`).
 *
 * Example AQL usage:
 * ```aql
 * LENGTH({a: 1, b: 2})   // returns 2
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\length;
 *
 * $expr = length('doc');
 * // Produces: 'LENGTH(doc)'
 * ```
 *
 * @param string $document The document variable or expression.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#length
 * @see count()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function length( string $document ) : string
{
    return func( DocumentFunction::LENGTH , $document ) ;
}
