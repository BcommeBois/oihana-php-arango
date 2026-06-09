<?php

namespace oihana\arango\db\functions\bit;

use oihana\arango\db\enums\functions\BitFunction;
use function oihana\core\strings\func;

/**
 * Construct a number with its bits set at the positions given in an array (zero-based).
 *
 * Wraps the ArangoDB AQL function `BIT_CONSTRUCT(positionsArray)`. It is the inverse of
 * {@see bitDeconstruct()}. A PHP array is emitted as a JSON literal; a string is passed
 * through as a raw AQL expression.
 *
 * Example AQL usage:
 * ```aql
 * BIT_CONSTRUCT([1, 2, 3])   // returns 14
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\bit\bitConstruct;
 *
 * $expr = bitConstruct([1, 2, 3]);   // 'BIT_CONSTRUCT([1,2,3])'
 * ```
 *
 * @param string|array $positions The bit positions to set (array literal or AQL expression).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_construct
 * @see bitDeconstruct()
 *
 * @package oihana\arango\db\functions\bit
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function bitConstruct( string|array $positions ) : string
{
    return func( BitFunction::BIT_CONSTRUCT , is_array( $positions ) ? json_encode( $positions ) : $positions ) ;
}
