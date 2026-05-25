<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Convert an IPv4 address string into its numeric representation.
 *
 * This helper wraps the ArangoDB AQL function `IPV4_TO_NUMBER(stringAddress)`
 * which converts an IPv4 address in dotted decimal notation into its 32-bit
 * numeric representation.
 *
 * Example AQL usage:
 * ```aql
 * IPV4_TO_NUMBER("127.0.0.1")   // returns 2130706433
 * IPV4_TO_NUMBER("192.168.0.1") // returns 3232235521
 * IPV4_TO_NUMBER("0.0.0.0")     // returns 0
 * IPV4_TO_NUMBER("255.255.255.255") // returns 4294967295
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\ipv4ToNumber;
 *
 * $expr = ipv4ToNumber('doc.ipAddress');
 * // Produces: 'IPV4_TO_NUMBER(doc.ipAddress)'
 * ```
 *
 * @param string $value IPv4 address string expression to convert.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#ipv4_to_number
 * @see ipv4FromNumber() For converting numeric to string IPv4.
 * @see isIPV4() For validating IPv4 addresses.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function ipv4ToNumber( string $value ) : string
{
    return func( StringFunction::IPV4_TO_NUMBER , $value ) ;
}

