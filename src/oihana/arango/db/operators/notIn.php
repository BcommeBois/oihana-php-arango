<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\Comparator;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if the left operand is not contained in the right operand array.
 *
 * Equivalent to the ArangoDB `NOT IN` comparison operator.
 *
 * Example:
 * - `42 NOT IN [ 2, 3, 1.5 ]` returns `true`
 * - `1.5 NOT IN [ 2, 3, 1.5 ]` returns `false`
 *
 * @param mixed $leftOperand  The value or expression to look for.
 * @param mixed $rightOperand The array expression to search in.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo notIn('42', '[ 2, 3, 1.5 ]');
 * // 42 NOT IN [ 2, 3, 1.5 ]
 *
 * echo notIn('user.role', "['admin','user']");
 * // user.role NOT IN ['admin','user']
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function notIn( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , Comparator::NOT_IN , $rightOperand ) ;
}
