<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if none of the elements of an array are contained in another array (NOT IN).
 *
 * Equivalent to the ArangoDB `NONE NOT IN` array comparison operator.
 *
 * Example:
 * - `[ 1, 2, 3 ] NONE NOT IN [ 1, 2, 3 ]` returns `true`
 * - `[ 1, 2, 3 ] NONE NOT IN [ 4, 5, 6 ]` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The array or expression to test membership against.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo noneNotIn('[ 1, 2, 3 ]', '[ 1, 2, 3 ]');
 * // [ 1, 2, 3 ] NONE NOT IN [ 1, 2, 3 ]
 *
 * echo noneNotIn('tags', '[ "a", "b" ]');
 * // tags NONE NOT IN [ "a", "b" ]
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function noneNotIn( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , ArrayComparator::NONE . Char::SPACE . Comparator::NOT_IN , $rightOperand ) ;
}
