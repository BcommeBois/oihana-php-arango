<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if none of the elements of an array are equal to a value (i.e., all elements are different).
 *
 * Equivalent to the ArangoDB `NONE !=` array comparison operator.
 *
 * Example:
 * - `[ 1, 2, 3 ] NONE != 4` returns `true`
 * - `[ 1, 2, 3 ] NONE != 3` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The value or expression to compare against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo noneNotEqual('[ 1, 2, 3 ]', 4);
 * // [ 1, 2, 3 ] NONE != 4
 *
 * echo noneNotEqual('scores', 10);
 * // scores NONE != 10
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function noneNotEqual( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , ArrayComparator::NONE . Char::SPACE . Comparator::NOT_EQUAL , $rightOperand );
}
