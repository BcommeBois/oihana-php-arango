<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CastingFunction;
use function oihana\core\strings\func;

/**
 * Converts a value of any type into an array.
 *
 * This helper wraps the ArangoDB AQL function `TO_ARRAY()`, which casts
 * the provided value to an array. If the value is already an array, it
 * is returned as is; otherwise, it will be wrapped in a single-element array.
 *
 * Example AQL usage:
 * ```aql
 * TO_ARRAY(doc.tags)   // returns doc.tags as an array
 * TO_ARRAY("single")   // returns ["single"]
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\toArray;
 *
 * $expr = toArray('doc.items');
 * // Produces: 'TO_ARRAY(doc.items)'
 * ```
 *
 * @param mixed $value The AQL field or expression to convert to an array.
 * @return string The formatted AQL expression (e.g., `'TO_ARRAY(doc.items)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#to_array
 * @package oihana\arango\db\functions
 * @since 1.0.0
 * author Marc Alcaraz
 */
function toArray( mixed $value ) :string
{
    return func( CastingFunction::TO_ARRAY , $value ) ;
}
