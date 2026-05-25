<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\Comparator;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that checks if the left string matches the right regular expression.
 *
 * Equivalent to the ArangoDB `=~` regular expression match operator.
 *
 * Example:
 * - `"foo" =~ "^f[o].$"` returns `true`
 * - `"bar" =~ "^f[o].$"` returns `false`
 *
 * @param mixed $leftOperand  The string value or expression to test.
 * @param mixed $rightOperand The regular expression pattern.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * ```php
 * echo isMatch('"foo"', '"^f[o].$"');
 * // "foo" =~ "^f[o].$"
 *
 * echo isMatch('doc.name', '"^[A-Z].+"');
 * // doc.name =~ "^[A-Z].+"
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isMatch( mixed $leftOperand , mixed $rightOperand ) :string
{
    return predicate( $leftOperand , Comparator::MATCH , $rightOperand ) ;
}
