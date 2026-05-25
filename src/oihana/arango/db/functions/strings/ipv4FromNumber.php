<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Convert a numeric IPv4 address value into its string representation.
 *
 * This helper wraps the ArangoDB AQL function `IPV4_FROM_NUMBER(numericAddress)`
 * which converts a numeric IPv4 address (32-bit integer) into its dotted decimal
 * string representation.
 *
 * Example AQL usage:
 * ```aql
 * IPV4_FROM_NUMBER(2130706433)  // returns "127.0.0.1"
 * IPV4_FROM_NUMBER(3232235521)  // returns "192.168.0.1"
 * IPV4_FROM_NUMBER(0)           // returns "0.0.0.0"
 * IPV4_FROM_NUMBER(4294967295)  // returns "255.255.255.255"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\ipv4FromNumber;
 *
 * $expr = ipv4FromNumber('doc.ipNumber');
 * // Produces: 'IPV4_FROM_NUMBER(doc.ipNumber)'
 * ```
 *
 * @param string $value Numeric IPv4 address expression to convert.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#ipv4_from_number
 * @see ipv4ToNumber() For converting string to numeric IPv4.
 * @see isIPV4() For validating IPv4 addresses.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function ipv4FromNumber( string $value ) : string
{
    return func( StringFunction::IPV4_FROM_NUMBER , $value ) ;
}

