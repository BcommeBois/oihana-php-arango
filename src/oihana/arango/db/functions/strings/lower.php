<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Convert uppercase letters to lowercase.
 *
 * This helper wraps the ArangoDB AQL function `LOWER(value)` which converts
 * all uppercase letters in a string to their lowercase counterparts while
 * leaving all other characters unchanged.
 *
 * Example AQL usage:
 * ```aql
 * LOWER("Hello World")           // returns "hello world"
 * LOWER("123 ABC")               // returns "123 abc"
 * LOWER(doc.title)               // converts title to lowercase
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\lower;
 *
 * $expr = lower('doc.title');
 * // Produces: 'LOWER(doc.title)'
 * ```
 *
 * @param string $value String expression to convert to lowercase.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#lower
 * @see upper() For converting to uppercase.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function lower( string $value ): string
{
    return func(StringFunction::LOWER , $value ) ;
}

