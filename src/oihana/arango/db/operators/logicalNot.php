<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\Logic;
use function oihana\core\strings\betweenParentheses;

/**
 * Returns an AQL expression that negates a predicate using the logical NOT operator (!).
 *
 * This function mirrors the LogicalTrait::not() method with a standalone functional API.
 *
 * Example semantics:
 * - `!(a == 2)` when parentheses are requested
 * - `!a == 2`   when parentheses are not requested
 *
 * @param mixed $expression      The expression or predicate to negate.
 * @param bool  $useParentheses  If true, wrap the expression in parentheses.
 * @param bool  $trim            Whether to trim existing `$left`/`$right` characters (default: false).
 *
 * @return string The AQL predicate string.
 *
 * @example
 * echo logicalNot('a == 2');
 * // !a == 2
 *
 * echo logicalNot('a == 2', true);
 * // !(a == 2)
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#logical-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function logicalNot( mixed $expression , bool $useParentheses = false , bool $trim = false ) : string
{
    return Logic::NOT . betweenParentheses( $expression , $useParentheses , $trim );
}
