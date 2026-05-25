<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Return the greatest element of an array.
 *
 * This helper wraps the ArangoDB AQL function `MAX(anyArray)` which returns
 * the maximum value from an array. The array is not limited to numbers and
 * can contain any comparable values.
 *
 * Example AQL usage:
 * ```aql
 * MAX([5, 2, 9, 2])             // returns 9
 * MAX(doc.scores)               // returns highest score
 * MAX(["a", "b", "c"])          // returns "c"
 * ```
 *
 * @param mixed $anyArray Array expression to find maximum value from.
 * @return string The formatted AQL expression.
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\aqlArray;
 * use function oihana\arango\db\functions\numerics\max;
 *
 * $expr = max('[5, 2, 9, 2]');
 * // Produces: 'MAX([5, 2, 9, 2])'
 *
 * $expr = max( aqlArray( [1,2,3] ) ) ;
 * // Produces: 'MAX([5, 2, 9, 2])'
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#max
 *
 * @see aqlArray() To convert a value to an AQL array expression.
 * @see min() For finding minimum value.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function max( mixed $anyArray ) : string
{
    return func( NumericFunction::MAX , $anyArray ) ;
}

