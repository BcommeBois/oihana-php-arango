<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the median value of the values in an array.
 *
 * This helper wraps the ArangoDB AQL function `MEDIAN(anyArray)` which returns
 * the median (middle value) of all values in the given array. The median is
 * the value separating the higher half from the lower half of the data set.
 *
 * Example AQL usage:
 * ```aql
 * MEDIAN([5, 2, 9, 2])          // returns 3.5 (average of 2 and 5)
 * MEDIAN([1, 2, 3, 4, 5])       // returns 3 (middle value)
 * MEDIAN([1, 2, 3, 4])          // returns 2.5 (average of 2 and 3)
 * MEDIAN(doc.scores)            // returns median of scores array
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\median;
 *
 * $expr = median('[5, 2, 9, 2]');
 * // Produces: 'MEDIAN([5, 2, 9, 2])'
 * ```
 *
 * @param mixed $anyArray Array expression containing numeric values.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#median
 * @see average() For calculating the mean.
 * @see percentile() For calculating percentiles.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function median( mixed $anyArray ) : string
{
    return func( NumericFunction::MEDIAN , $anyArray ) ;
}

