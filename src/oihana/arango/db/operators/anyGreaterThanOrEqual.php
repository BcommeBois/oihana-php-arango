<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if **any elements** of an array are greater than or equal to a given value.
 *
 * Equivalent to the ArangoDB `ANY >=` array comparison operator.
 *
 * Example:
 * - `[ 3, 4, 5 ] ANY >= 2` returns `true`
 * - `[ 1, 2, 3 ] ANY >= 5` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The value or expression to compare against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo anyGreaterThanOrEqual('[ 1, 2, 3 ]', 2);
 * // [ 1, 2, 3 ] ANY >= 2
 *
 * echo anyGreaterThanOrEqual('scores', 10);
 * // scores ANY >= 10
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function anyGreaterThanOrEqual( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate
    (
        $leftOperand ,
        ArrayComparator::ANY . Char::SPACE . Comparator::GREATER_THAN_OR_EQUAL ,
        $rightOperand
    );
}