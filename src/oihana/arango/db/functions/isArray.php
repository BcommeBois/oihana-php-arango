<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression that checks whether a given value is an array (list or object array).
 *
 * This helper wraps the ArangoDB AQL function `IS_ARRAY()` and returns a string
 * representation of the corresponding expression.
 *
 * The AQL function `IS_ARRAY(value)` evaluates to `true` if the provided value
 * is an array (either a list or an object array), and `false` otherwise.
 *
 * Example output:
 * ```aql
 * IS_ARRAY(doc.tags)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\isArray;
 *
 * $expr = isArray('doc.tags');
 * // Produces: 'IS_ARRAY(doc.tags)'
 * ```
 *
 * @param string $value The AQL field or expression to check (e.g. `'doc.tags'`).
 * @return string The AQL expression as a string (e.g. `'IS_ARRAY(doc.tags)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#is_array
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isArray( mixed $value ) :string
{
    return func( CheckFunction::IS_ARRAY , $value ) ;
}