<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Convert lowercase letters to uppercase.
 *
 * This helper wraps the ArangoDB AQL function `UPPER(value)` which converts
 * all lowercase letters in a string to their uppercase counterparts while
 * leaving all other characters unchanged.
 *
 * Example AQL usage:
 * ```aql
 * UPPER("hello world")           // returns "HELLO WORLD"
 * UPPER("123 abc")               // returns "123 ABC"
 * UPPER(doc.title)               // converts title to uppercase
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\upper;
 *
 * $expr = upper('doc.title');
 * // Produces: 'UPPER(doc.title)'
 * ```
 *
 * @param string $value String expression to convert to uppercase.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#upper
 * @see lower() For converting to lowercase.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function upper( string $value ): string
{
    return func(StringFunction::UPPER , $value ) ;
}

