<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return an AQL value described by the JSON-encoded input string.
 *
 * This helper wraps the ArangoDB AQL function `JSON_PARSE(text)` which parses
 * a JSON string and returns the corresponding AQL value. This is useful for
 * converting JSON strings back into native AQL data types.
 *
 * Example AQL usage:
 * ```aql
 * JSON_PARSE('{"name": "John"}')    // returns {name: "John"}
 * JSON_PARSE('[1, 2, 3]')          // returns [1, 2, 3]
 * JSON_PARSE('"hello"')            // returns "hello"
 * JSON_PARSE('true')               // returns true
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\jsonParse;
 *
 * $expr = jsonParse('doc.jsonString');
 * // Produces: 'JSON_PARSE(doc.jsonString)'
 * ```
 *
 * @param string $text JSON string expression to parse.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#json_parse
 * @see jsonStringify() For converting AQL values to JSON strings.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function jsonParse( string $text ) : string
{
    return func( StringFunction::JSON_PARSE , $text ) ;
}

