<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\Comparator;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if the left operand is contained in the right operand array.
 *
 * Equivalent to the ArangoDB `IN` comparison operator.
 *
 * Example:
 * - `1.5 IN [ 2, 3, 1.5 ]` returns `true`
 * - `42 IN [ 2, 3, 1.5 ]` returns `false`
 *
 * @param mixed $leftOperand  The value or expression to look for.
 * @param mixed $rightOperand The array expression to search in.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo in('1.5', '[ 2, 3, 1.5 ]');
 * // 1.5 IN [ 2, 3, 1.5 ]
 *
 * echo in('user.role', "['admin','user']");
 * // user.role IN ['admin','user']
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function in( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , Comparator::IN , $rightOperand ) ;
}
