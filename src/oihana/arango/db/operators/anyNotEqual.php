<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if **any elements** of an array are **not equal** to a given value.
 *
 * Equivalent to the ArangoDB `ANY !=` array comparison operator.
 *
 * Example:
 * - `[ 1, 2, 3 ] ANY != 4` returns `true`
 * - `[ 2, 2, 2 ] ANY != 2` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The value or expression to compare against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo anyNotEqual('[ 1, 2, 3 ]', 2);
 * // [ 1, 2, 3 ] ANY != 2
 *
 * echo anyNotEqual('scores', 10);
 * // scores ANY != 10
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function anyNotEqual( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate
    (
        $leftOperand ,
        ArrayComparator::ANY . Char::SPACE . Comparator::NOT_EQUAL ,
        $rightOperand
    ) ;
}