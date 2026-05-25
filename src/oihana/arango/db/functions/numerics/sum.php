<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the sum of the values in an array.
 *
 * This helper wraps the ArangoDB AQL function `SUM(numArray)` which calculates
 * the sum of all numeric values in the given array.
 *
 * Example AQL usage:
 * ```aql
 * SUM([1, 2, 3, 4])             // returns 10
 * SUM(doc.scores)               // returns sum of all scores
 * SUM([5, 10, 15])              // returns 30
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\sum;
 *
 * $expr = sum('[1, 2, 3, 4]');
 * // Produces: 'SUM([1, 2, 3, 4])'
 * ```
 *
 * @param mixed $numArray Array expression containing numeric values to sum.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#sum
 * @see average() For calculating the mean.
 * @see product() For calculating the product.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function sum( mixed $numArray ) : string
{
    return func( NumericFunction::SUM , $numArray ) ;
}

