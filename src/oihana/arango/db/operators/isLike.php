<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\Comparator;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if the left string matches the right LIKE pattern.
 *
 * Equivalent to the ArangoDB `LIKE` comparison operator.
 *
 * Example:
 * - `"foo" LIKE "f%"` returns `true`
 * - `"bar" LIKE "f%"` returns `false`
 *
 * @param mixed $leftOperand  The string value or expression to test.
 * @param mixed $rightOperand The LIKE pattern expression.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo isLike('"foo"', '"f%"');
 * // "foo" LIKE "f%"
 *
 * echo isLike('doc.name', '"A_%"');
 * // doc.name LIKE "A_%"
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isLike( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , Comparator::LIKE , $rightOperand ) ;
}
