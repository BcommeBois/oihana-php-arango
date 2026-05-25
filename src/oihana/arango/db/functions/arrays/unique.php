<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Return all unique elements in anyArray. To determine uniqueness, the function will use the comparison order.
 *
 * Example AQL usage:
 * ```aql
 * RETURN UNIQUE( [ 1,2,2,3,3,3,4,4,4,4,5,5,5,5,5 ] )
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\unique;
 *
 * $expr = unique('[1, 1, 2, 3]');
 * // Produces: 'UNIQUE([1, 1, 2, 3])'
 * ```
 *
 * @param mixed $anyArray an array with elements of arbitrary type
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arango.ai/arangodb/stable/aql/functions/array/#unique
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function unique( mixed $anyArray ) : string
{
    return func( ArrayFunction::UNIQUE ,  $anyArray ) ;
}

