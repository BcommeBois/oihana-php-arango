<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return a JSON string representation of the input value.
 *
 * This helper wraps the ArangoDB AQL function `JSON_STRINGIFY(value)` which
 * converts an AQL value into its JSON string representation. This is useful
 * for serializing AQL data types to JSON format.
 *
 * Example AQL usage:
 * ```aql
 * JSON_STRINGIFY({name: "John"})    // returns '{"name":"John"}'
 * JSON_STRINGIFY([1, 2, 3])         // returns '[1,2,3]'
 * JSON_STRINGIFY("hello")           // returns '"hello"'
 * JSON_STRINGIFY(true)              // returns 'true'
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\jsonStringify;
 *
 * $expr = jsonStringify('doc.data');
 * // Produces: 'JSON_STRINGIFY(doc.data)'
 * ```
 *
 * @param mixed $value AQL value expression to convert to JSON string.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#json_stringify
 * @see jsonParse() For parsing JSON strings back to AQL values.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function jsonStringify( mixed $value ) : string
{
    return func( StringFunction::JSON_STRINGIFY , $value ) ;
}

