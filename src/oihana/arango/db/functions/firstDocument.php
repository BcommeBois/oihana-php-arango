<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Return the first alternative that is a document, and null if none of the alternatives is a document.
 *
 * This helper wraps the ArangoDB AQL function `FIRST_DOCUMENT()`.
 *
 * Example AQL output:
 * ```aql
 * FIRST_DOCUMENT(null, null, "foo", "bar") // "foo"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\firstDocument;
 *
 * $expr = firstDocument('null,doc,'hello');
 * // Produces: 'FIRST_DOCUMENT(null,doc,'hello')'
 * ```
 *
 * @param mixed ...$alternative input of arbitrary type
 *
 * @return string The formatted AQL expression (e.g. `'FIRST_DOCUMENT(alternative,....)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#first_document
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function firstDocument( mixed ...$alternative ) :string
{
    return func( MiscFunction::FIRST_DOCUMENT , $alternative ) ;
}
