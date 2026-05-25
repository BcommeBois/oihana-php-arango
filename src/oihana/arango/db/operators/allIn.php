<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if **all elements** of an array are contained in another array.
 *
 * Equivalent to the ArangoDB `ALL IN` array comparison operator.
 *
 * Example:
 * - `[1, 2, 3] ALL IN [1, 2, 3]` returns `true`
 * - `[1, 2, 4] ALL IN [1, 2, 3]` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The array or expression to check membership against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo allIn('[1, 2, 3]', '[1, 2, 3]');
 * // [1, 2, 3] ALL IN [1, 2, 3]
 *
 * echo allIn('scores', '[10, 20, 30]');
 * // scores ALL IN [10, 20, 30]
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function allIn( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , ArrayComparator::ALL . Char::SPACE . Comparator::IN , $rightOperand ) ;
}