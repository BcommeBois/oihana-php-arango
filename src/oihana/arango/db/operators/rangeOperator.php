<?php

namespace oihana\arango\db\operators;

use oihana\enums\Char;

/**
 * Generates an AQL range expression using the range operator (`..`).
 *
 * The range operator can be used in AQL `FOR` loops to iterate over
 * a sequence of numeric values, inclusive of both `$minimum` and `$maximum`.
 *
 * Example output:
 * ```aql
 * 2010..2013
 * ```
 * which can be iterated as `[2010, 2011, 2012, 2013]` in AQL queries.
 *
 * @param int|float|string $minimum The start value of the range.
 * @param int|float|string $maximum The end value of the range.
 *
 * @return string The AQL range expression as a string.
 *
 * @example
 * ```php
 * echo rangeOperator(2010, 2013);
 * // Outputs: "2010 .. 2013"
 *
 * echo rangeOperator('1', '5');
 * // Outputs: "1 .. 5"
 * ```
 *
 * @see https://docs.arangodb.com/3.11/aql/operators/#range-operator
 */
function rangeOperator( mixed $minimum , mixed $maximum ) :string
{
    $operation = [ $minimum , Char::DOT . Char::DOT , $maximum ] ;
    return implode( Char::SPACE , $operation ) ;
}