<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the number of attribute keys of a document (an alias of {@see length()} / `LENGTH()`).
 *
 * Wraps the ArangoDB AQL function `COUNT(document)`.
 *
 * Example AQL usage:
 * ```aql
 * COUNT({a: 1, b: 2})   // returns 2
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\count;
 *
 * $expr = count('doc');
 * // Produces: 'COUNT(doc)'
 * ```
 *
 * @param string $document The document variable or expression.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#count
 * @see length()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function count( string $document ) : string
{
    return func( DocumentFunction::COUNT , $document ) ;
}
