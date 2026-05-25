<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression that checks whether a value is an object/document.
 *
 * This helper wraps the ArangoDB AQL function `IS_OBJECT()`, which returns
 * `true` if the provided value is an object (e.g., a document in AQL), and `false` otherwise.
 *
 * Example AQL output:
 * ```aql
 * IS_OBJECT(doc.user)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\isObject;
 *
 * $expr = isObject('doc.user');
 * // Produces: 'IS_OBJECT(doc.user)'
 * ```
 *
 * @param string $value The AQL field or expression to check (e.g., `'doc.user'`).
 * @return string The formatted AQL expression (e.g., `'IS_OBJECT(doc.user)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#is_object
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function isObject( mixed $value ) :string
{
    return func( CheckFunction::IS_OBJECT , $value ) ;
}
