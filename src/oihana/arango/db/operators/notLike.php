<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\Comparator;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if the left string does not match the right LIKE pattern.
 *
 * Equivalent to the ArangoDB `NOT LIKE` comparison operator.
 *
 * Example:
 * - `"foo" NOT LIKE "f%"` returns `false`
 * - `"bar" NOT LIKE "f%"` returns `true`
 *
 * @param mixed $leftOperand  The string value or expression to test.
 * @param mixed $rightOperand The LIKE pattern expression.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo notLike('"foo"', '"f%"');
 * // "foo" NOT LIKE "f%"
 *
 * echo notLike('doc.name', '"A_%"');
 * // doc.name NOT LIKE "A_%"
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function notLike( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , Comparator::NOT_LIKE , $rightOperand ) ;
}
