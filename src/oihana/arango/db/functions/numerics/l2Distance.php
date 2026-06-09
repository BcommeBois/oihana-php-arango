<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the L2 (Euclidean) distance between two vectors.
 *
 * This helper wraps the ArangoDB AQL function `L2_DISTANCE(x, y)` which
 * calculates the straight-line distance between two equally-sized numeric
 * vectors (the square root of the sum of the squared component differences).
 * The smaller the value, the closer the vectors are.
 *
 * Unlike {@see approxNearL2()}, this computes the *exact* distance and does
 * not require (nor benefit from) a vector index.
 *
 * Example AQL usage:
 * ```aql
 * L2_DISTANCE([1, 2], [4, 6])   // returns 5 (sqrt(3² + 4²))
 * L2_DISTANCE([0, 0], [0, 0])   // returns 0 (identical vectors)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\l2Distance;
 *
 * $expr = l2Distance('[1, 2]', '[4, 6]');
 * // Produces: 'L2_DISTANCE([1, 2],[4, 6])'
 * ```
 *
 * @param string|int $x First input array (vector).
 * @param string|int $y Second input array (vector).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#l2_distance
 * @see l1Distance() For the Manhattan distance.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function l2Distance( string|int $x , string|int $y ) : string
{
    return func( NumericFunction::L2_DISTANCE , [ $x , $y ] ) ;
}
