<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the cosine similarity between two vectors.
 *
 * This helper wraps the ArangoDB AQL function `COSINE_SIMILARITY(x, y)` which
 * calculates the cosine similarity between two vectors. Cosine similarity
 * measures the cosine of the angle between two vectors, ranging from -1 to 1.
 *
 * Example AQL usage:
 * ```aql
 * COSINE_SIMILARITY([1, 0], [1, 0])     // returns 1 (identical vectors)
 * COSINE_SIMILARITY([1, 0], [0, 1])     // returns 0 (orthogonal vectors)
 * COSINE_SIMILARITY([1, 0], [-1, 0])    // returns -1 (opposite vectors)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\cosSimilarity;
 *
 * $expr = cosSimilarity('[1, 0]', '[0, 1]');
 * // Produces: 'COSINE_SIMILARITY([1, 0], [0, 1])'
 * ```
 *
 * @param string|int $x First input array (vector).
 * @param string|int $y Second input array (vector).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#cosine_similarity
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function cosSimilarity( string|int $x , string|int $y ) : string
{
    return func( NumericFunction::COSINE_SIMILARITY , [ $x , $y ] ) ;
}

