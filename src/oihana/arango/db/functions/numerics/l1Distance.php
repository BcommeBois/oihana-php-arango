<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the L1 (Manhattan / taxicab) distance between two vectors.
 *
 * This helper wraps the ArangoDB AQL function `L1_DISTANCE(x, y)` which
 * calculates the sum of the absolute differences between the components of
 * two equally-sized numeric vectors. The smaller the value, the closer the
 * vectors are.
 *
 * Unlike {@see approxNearL2()}, this computes the *exact* distance and does
 * not require (nor benefit from) a vector index.
 *
 * Example AQL usage:
 * ```aql
 * L1_DISTANCE([1, 2], [4, 6])   // returns 7 (|1-4| + |2-6|)
 * L1_DISTANCE([0, 0], [0, 0])   // returns 0 (identical vectors)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\l1Distance;
 *
 * $expr = l1Distance('[1, 2]', '[4, 6]');
 * // Produces: 'L1_DISTANCE([1, 2],[4, 6])'
 * ```
 *
 * @param string|int $x First input array (vector).
 * @param string|int $y Second input array (vector).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#l1_distance
 * @see l2Distance() For the Euclidean distance.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function l1Distance( string|int $x , string|int $y ) : string
{
    return func( NumericFunction::L1_DISTANCE , [ $x , $y ] ) ;
}
