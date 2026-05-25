<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if **all elements** of an array are less than or equal to a given value.
 *
 * Equivalent to the ArangoDB `ALL <=` array comparison operator.
 *
 * Example:
 * - `[ 3, 4, 5 ] ALL <= 5` returns `true`
 * - `[ 1, 2, 3 ] ALL <= 2` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The value or expression to compare against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo allGreaterThanOrEqual('[ 1, 2, 3 ]', 2);
 * // [ 1, 2, 3 ] ALL <= 2
 *
 * echo allGreaterThanOrEqual('scores', 10);
 * // scores ALL <= 10
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function allLessThanOrEqual( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate
    (
        $leftOperand ,
        ArrayComparator::ALL . Char::SPACE . Comparator::LESS_THAN_OR_EQUAL ,
        $rightOperand
    );
}