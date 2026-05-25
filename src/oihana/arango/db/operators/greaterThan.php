<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\Comparator;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if the left operand is greater than the right operand.
 *
 * Equivalent to the ArangoDB `>` comparison operator.
 *
 * Example:
 * - `3 > 2` returns `true`
 * - `2 > 3` returns `false`
 *
 * @param mixed $leftOperand  The left-hand value or expression.
 * @param mixed $rightOperand The right-hand value or expression to compare against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo greaterThan('a', 12);
 * // a > 12
 *
 * echo greaterThan('doc.score', 90);
 * // doc.score > 90
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function greaterThan( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , Comparator::GREATER_THAN , $rightOperand ) ;
}
