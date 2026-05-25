<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if none of the elements of an array are greater than a value.
 *
 * Equivalent to the ArangoDB `NONE >` array comparison operator.
 *
 * Example:
 * - `[ 1, 2, 3 ] NONE > 5` returns `true`
 * - `[ 1, 2, 3 ] NONE > 2` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The value or expression to compare against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo noneGreaterThan('[ 1, 2, 3 ]', 5);
 * // [ 1, 2, 3 ] NONE > 5
 *
 * echo noneGreaterThan('scores', 10);
 * // scores NONE > 10
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function noneGreaterThan( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , ArrayComparator::NONE . Char::SPACE . Comparator::GREATER_THAN , $rightOperand );
}
