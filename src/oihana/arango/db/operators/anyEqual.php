<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if **any elements** of an array satisfy a comparison.
 *
 * Equivalent to the ArangoDB `ANY ==` array comparison operator.
 *
 * Example:
 * - `[ 1, 2, 3 ] ANY == 2` returns `true`
 * - `[ 1, 2, 3 ] ANY == 5` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The value or expression to compare against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo anyEqual('[ 1, 2, 3 ]', 2);
 * // [ 1, 2, 3 ] ANY == 2
 *
 * echo anyEqual('scores', 10);
 * // scores ANY == 10
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function anyEqual( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , ArrayComparator::ANY . Char::SPACE . Comparator::EQUAL , $rightOperand ) ;
}