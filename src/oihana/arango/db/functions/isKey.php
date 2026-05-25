<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\CheckFunction;
use function oihana\core\strings\func;

/**
 * Builds an AQL expression that checks whether a value is a valid document key.
 *
 * This helper wraps the ArangoDB AQL function `IS_KEY()`, which tests if
 * the given value is a string that can be used as the `_key` attribute of a document.
 *
 * The function returns `true` if the string matches ArangoDB’s key format rules:
 * - Contains only letters, digits, and the characters `_`, `-`, `:`, or `@`.
 * - Does not contain `/`, whitespace, or control characters.
 * - Must not be empty.
 *
 * Example AQL output:
 * ```aql
 * IS_KEY(user._key)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\isKey;
 *
 * $expr = isKey('user._key');
 * // Produces: 'IS_KEY(user._key)'
 * ```
 *
 * @param string $value The AQL field or string expression to check (e.g. `'user._key'`).
 * @return string The formatted AQL expression (e.g. `'IS_KEY(user._key)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/#is_key
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isKey( mixed $value ) :string
{
    return func( CheckFunction::IS_KEY , $value ) ;
}
