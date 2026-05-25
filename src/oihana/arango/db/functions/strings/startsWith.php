<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Check whether a string starts with a prefix.
 *
 * This helper wraps the ArangoDB AQL function `STARTS_WITH(text, prefix)` which
 * checks if the given string starts with the specified prefix. The comparison
 * is case-sensitive.
 *
 * Example AQL usage:
 * ```aql
 * STARTS_WITH("hello world", "hello")       // returns true
 * STARTS_WITH("hello world", "world")       // returns false
 * STARTS_WITH("Hello world", "hello")       // returns false (case-sensitive)
 * STARTS_WITH("", "")                       // returns true (empty strings)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\startsWith;
 *
 * $expr = startsWith('doc.name', '"John"');
 * // Produces: 'STARTS_WITH(doc.name, "John")'
 * ```
 *
 * @param string $value String expression to check.
 * @param string $prefix Prefix string to test for.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#starts_with
 * @see contains() For checking if string contains substring.
 * @see like() For pattern matching.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function startsWith( string $value , string $prefix ): string
{
    return func(StringFunction::STARTS_WITH , [ $value , $prefix ] ) ;
}

