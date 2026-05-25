<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression that checks whether a value is a number.
 *
 * This helper wraps the ArangoDB AQL function `IS_NUMBER()`, which returns
 * `true` if the given value is a numeric type, and `false` otherwise.
 *
 * Example AQL output:
 * ```aql
 * IS_NUMBER(doc.age)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\isNumber;
 *
 * $expr = isNumber('doc.age');
 * // Produces: 'IS_NUMBER(doc.age)'
 * ```
 *
 * @param string $value The AQL field or expression to check (e.g. `'doc.age'`).
 * @return string The formatted AQL expression (e.g. `'IS_NUMBER(doc.age)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#is_number
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function isNumber( mixed $value ) :string
{
    return func( CheckFunction::IS_NUMBER , $value ) ;
}
