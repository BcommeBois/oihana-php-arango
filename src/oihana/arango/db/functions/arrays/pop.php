<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Remove the last element of array.
 *
 * This helper wraps the ArangoDB AQL function `POP(anyArray)` which removes
 * and returns the last element of an array, modifying the original array.
 *
 * If it’s already empty or has only a single element left, an empty array is returned.
 *
 * Example AQL usage:
 * ```aql
 * RETURN POP( [ 1, 2, 3, 4 ] )
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\pop;
 *
 * $expr = pop('[1, 2, 3]');
 * // Produces: 'POP([1, 2, 3])'
 * ```
 *
 * @param mixed $anyArray an array with elements of arbitrary type
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arango.ai/arangodb/stable/aql/functions/array/#pop
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function pop( mixed $anyArray ) : string
{
    return func( ArrayFunction::POP ,  $anyArray ) ;
}

