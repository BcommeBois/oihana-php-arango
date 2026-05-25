<?php

namespace oihana\arango\db\operators;

use oihana\arango\db\enums\Logic;
use function oihana\core\strings\predicate;

/**
 * Returns an AQL expression that combines two predicates using the logical AND operator (&&).
 *
 * This function mirrors the LogicalTrait::and() method with a standalone functional API.
 *
 * Example semantics:
 * - `a == 2 && b == 3`
 *
 * @param mixed $leftOperand  The left-hand expression/predicate.
 * @param mixed $rightOperand The right-hand expression/predicate.
 *
 * @return string The AQL predicate string.
 *
 * @example
 * echo logicalAnd('a == 2', 'b == 3');
 * // a == 2 && b == 3
 *
 * @see https://docs.arangodb.com/stable/aql/operators/#logical-operators
 *
 * @package oihana\arango\db\operators
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function logicalAnd( mixed $leftOperand , mixed $rightOperand ) : string
{
    return predicate( $leftOperand , Logic::AND , $rightOperand );
}
