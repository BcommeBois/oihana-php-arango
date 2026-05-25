<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if none of the elements of an array are contained in another array.
 *
 * Equivalent to the ArangoDB `NONE IN` array comparison operator.
 *
 * Example:
 * - `[ 1, 2, 3 ] NONE IN [ 4, 5, 6 ]` returns `true`
 * - `[ 1, 2, 3 ] NONE IN [ 2, 5, 6 ]` returns `false`
 *
 * @param mixed $leftOperand  The array or expression to evaluate.
 * @param mixed $rightOperand The array or expression to test membership in.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo noneIn('[ 1, 2, 3 ]', '[ 4, 5, 6 ]');
 * // [ 1, 2, 3 ] NONE IN [ 4, 5, 6 ]
 *
 * echo noneIn('tags', '[ "a", "b" ]');
 * // tags NONE IN [ "a", "b" ]
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function noneIn( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , ArrayComparator::NONE . Char::SPACE . Comparator::IN , $rightOperand ) ;
}
