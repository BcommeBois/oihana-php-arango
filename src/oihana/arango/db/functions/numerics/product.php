<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return the product of the values in an array.
 *
 * This helper wraps the ArangoDB AQL function `PRODUCT(numArray)` which calculates
 * the product (multiplication) of all numeric values in the given array.
 *
 * Example AQL usage:
 * ```aql
 * PRODUCT([1, 2, 3, 4])         // returns 24 (1×2×3×4)
 * PRODUCT([5, 2])               // returns 10 (5×2)
 * PRODUCT([10])                 // returns 10 (single value)
 * PRODUCT(doc.factors)          // returns product of factors array
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\product;
 *
 * $expr = product('[1, 2, 3, 4]');
 * // Produces: 'PRODUCT([1, 2, 3, 4])'
 * ```
 *
 * @param mixed $numArray Array expression containing numeric values to multiply.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#product
 * @see sum() For calculating the sum.
 * @see average() For calculating the mean.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function product( mixed $numArray ) : string
{
    return func( NumericFunction::PRODUCT , $numArray ) ;
}

