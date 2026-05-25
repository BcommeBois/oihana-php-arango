<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Returns the data type name of the given value in AQL.
 *
 * This helper wraps the ArangoDB AQL function `TYPENAME()`, which returns a string
 * representing the type of the provided value.
 *
 * Possible return values:
 * - `"null"`
 * - `"bool"`
 * - `"number"`
 * - `"string"`
 * - `"array"`
 * - `"object"`
 *
 * Example AQL output:
 * ```aql
 * TYPENAME(doc.age)  // "number"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\typeName;
 *
 * $expr = typeName('doc.age');
 * // Produces: 'TYPENAME(doc.age)'
 * ```
 *
 * @param mixed $value The AQL field or expression to evaluate.
 * @return string The formatted AQL expression (e.g., `'TYPENAME(doc.age)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#typename
 * @package oihana\arango\db\functions
 * @since 1.0.0
 * author Marc Alcaraz
 */
function typeName( mixed $value ) :string
{
    return func( CheckFunction::TYPENAME , $value ) ;
}
