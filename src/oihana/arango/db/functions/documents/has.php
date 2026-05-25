<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Returns an AQL expression to test whether an attribute exists in a document.
 *
 * This helper wraps the ArangoDB AQL function `HAS(document, attributeName)` which
 * returns true if the document has an attribute named `attributeName`, even if its
 * value is falsy (null, 0, false, or empty string).
 *
 * Example AQL usage:
 * ```aql
 * HAS(doc, "name")              // returns true if doc has a "name" attribute
 * HAS(doc, "email")             // returns true if doc has an "email" attribute
 * HAS(doc, "nonExistent")       // returns false if attribute doesn't exist
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\has;
 *
 * $expr = has('doc', 'name');
 * // Produces: 'HAS(doc, "name")'
 * ```
 *
 * @param string $document The document variable or expression to test.
 * @param string $attributeName The attribute key to test for existence.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#has
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function has( string $document , string $attributeName ) : string
{
    return func( DocumentFunction::HAS , [ $document , $attributeName ] ) ;
}

