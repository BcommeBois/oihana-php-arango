<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the smallest element of an array.
 *
 * This helper wraps the ArangoDB AQL function `MIN(anyArray)` which returns
 * the minimum value from an array. The array is not limited to numbers and
 * can contain any comparable values.
 *
 * Example AQL usage:
 * ```aql
 * MIN([5, 2, 9, 2])             // returns 2
 * MIN(doc.scores)               // returns lowest score
 * MIN(["a", "b", "c"])          // returns "a"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\min;
 *
 * $expr = min('[5, 2, 9, 2]');
 * // Produces: 'MIN([5, 2, 9, 2])'
 * ```
 *
 * @param mixed $anyArray Array expression to find minimum value from.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#min
 * @see max() For finding maximum value.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function min( mixed $anyArray ) : string
{
    return func( NumericFunction::MIN , $anyArray ) ;
}

