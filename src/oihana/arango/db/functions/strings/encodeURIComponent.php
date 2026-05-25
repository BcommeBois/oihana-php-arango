<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the URI component-encoded string of a value.
 *
 * This helper wraps the ArangoDB AQL function `ENCODE_URI_COMPONENT(value)` which
 * returns the URI component-encoded version of the input string. This is useful
 * for encoding special characters in URL components.
 *
 * Example AQL usage:
 * ```aql
 * ENCODE_URI_COMPONENT("hello world")       // returns "hello%20world"
 * ENCODE_URI_COMPONENT("a+b=c")             // returns "a%2Bb%3Dc"
 * ENCODE_URI_COMPONENT(doc.name)            // returns encoded name
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\encodeURIComponent;
 *
 * $expr = encodeURIComponent('doc.name');
 * // Produces: 'ENCODE_URI_COMPONENT(doc.name)'
 * ```
 *
 * @param string $value String expression to encode.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#encode_uri_component
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function encodeURIComponent( string $value ): string
{
    return func(StringFunction::ENCODE_URI_COMPONENT , $value ) ;
}

