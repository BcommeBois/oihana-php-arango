<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the average (arithmetic mean) of the values in an array.
 *
 * This helper wraps the ArangoDB AQL function `AVERAGE(numArray)` which calculates
 * the arithmetic mean of all numeric values in the given array.
 *
 * Example AQL usage:
 * ```aql
 * AVERAGE([5, 2, 9, 2])         // returns 4.5
 * AVERAGE(doc.scores)           // returns average of scores array
 * AVERAGE([1, 2, 3, 4, 5])      // returns 3.0
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\average;
 *
 * $expr = average('[5, 2, 9, 2]');
 * // Produces: 'AVERAGE([5, 2, 9, 2])'
 * ```
 *
 * @param mixed $anyArray Array expression containing numeric values.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#average
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function average( mixed $anyArray ) : string
{
    return func( NumericFunction::AVERAGE , $anyArray ) ;
}

