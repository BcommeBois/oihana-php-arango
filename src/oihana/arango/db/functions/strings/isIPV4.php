<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Check if an arbitrary string is suitable for interpretation as an IPv4 address.
 *
 * This helper wraps the ArangoDB AQL function `IS_IPV4(value)` which checks
 * whether the given string is a valid IPv4 address in dotted decimal notation.
 *
 * Example AQL usage:
 * ```aql
 * IS_IPV4("127.0.0.1")         // returns true
 * IS_IPV4("192.168.0.1")       // returns true
 * IS_IPV4("256.0.0.1")         // returns false (invalid octet)
 * IS_IPV4("not an ip")         // returns false
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\isIPV4;
 *
 * $expr = isIPV4('doc.ipAddress');
 * // Produces: 'IS_IPV4(doc.ipAddress)'
 * ```
 *
 * @param string $value String expression to validate as IPv4 address.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#is_ipv4
 * @see ipv4FromNumber() For converting numeric to string IPv4.
 * @see ipv4ToNumber() For converting string to numeric IPv4.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function isIPV4( string $value ) : string
{
    return func( StringFunction::IS_IPV4 , $value ) ;
}

