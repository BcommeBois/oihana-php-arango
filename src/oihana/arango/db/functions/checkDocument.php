<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Returns true if document is a valid document object, i.e. a document without any duplicate attribute names.
 * Will return false for any non-objects/non-documents or documents with duplicate attribute names.
 *
 * This helper wraps the ArangoDB AQL function `CHECK_DOCUMENT()`.
 *
 * Example AQL output:
 * ```aql
 * CHECK_DOCUMENT(doc)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\firstDocument;
 *
 * $expr = checkDocument('doc');
 * // Produces: 'CHECK_DOCUMENT(doc)'
 * ```
 *
 * @param mixed $document
 *
 * @return string The formatted AQL expression (e.g. `'CHECK_DOCUMENT(doc)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#check_document
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function checkDocument( mixed $document ) :string
{
    return func( MiscFunction::CHECK_DOCUMENT , $document ) ;
}
