<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use oihana\arango\db\enums\PercentileMethod;
use oihana\enums\Char;
use function oihana\core\numbers\clip;
use function oihana\core\strings\compile;
use function oihana\core\strings\func;

/**
 * Return the nth percentile of the values in an array.
 *
 * This helper wraps the ArangoDB AQL function `PERCENTILE(numArray, n, method)`
 * which returns the nth percentile of all values in the given array. The position
 * must be between 0 (excluded) and 100 (included).
 *
 * Example AQL usage:
 * ```aql
 * PERCENTILE([1, 2, 3, 4, 5], 50)                  // returns 3 (50th percentile)
 * PERCENTILE([1, 2, 3, 4, 5], 25)                  // returns 2 (25th percentile)
 * PERCENTILE([1, 2, 3, 4, 5], 75)                  // returns 4 (75th percentile)
 * PERCENTILE([1, 2, 3, 4, 5], 50, "interpolation") // returns 3 (with interpolation)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\percentile;
 *
 * $expr = percentile('[1, 2, 3, 4, 5]', 50);
 * // Produces: 'PERCENTILE([1, 2, 3, 4, 5], 50)'
 *
 * $expr = percentile('[1, 2, 3, 4, 5]', 50, 'interpolation');
 * // Produces: 'PERCENTILE([1, 2, 3, 4, 5], 50, "interpolation")'
 * ```
 *
 * @param mixed $numArray Array expression containing numeric values (null values are ignored).
 * @param int $position The percentile position (must be between 0 and 100).
 * @param string|null $method Optional method: "rank" (default) or "interpolation".
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#percentile
 * @see median() For the 50th percentile.
 * @see average() For calculating the mean.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function percentile( mixed $numArray, int $position , ?string $method ) : string
{
    $position = clip( $position , 0 , 100 ) ;
    return func( NumericFunction::PERCENTILE , compile( [ $numArray , $position , $method == PercentileMethod::INTERPOLATION ? $method : Char::EMPTY ] , Char::COMMA ) ) ;
}

