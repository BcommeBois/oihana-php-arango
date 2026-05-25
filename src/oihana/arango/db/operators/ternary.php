<?php

namespace oihana\arango\db\operators;

use oihana\enums\Char;

/**
 * Builds a ternary AQL (or general) expression as a string.
 *
 * Generates a string in the format:
 * ```
 * condition ? trueValue : falseValue
 * ```
 *
 * The ternary operator evaluates the `$condition`:
 * - If `$condition` is true, the result is `$trueValue`.
 * - Otherwise, the result is `$falseValue`.
 *
 * This is useful for dynamically constructing AQL expressions with conditional logic.
 *
 * @param string     $condition  Boolean expression to evaluate (e.g., "IS_ARRAY(doc.tags)").
 * @param mixed|null $trueValue  Value or expression returned if the condition is true.
 * @param mixed|null $falseValue Value or expression returned if the condition is false.
 *
 * @return string The assembled ternary expression as a string.
 *
 * @example
 * ```php
 * $expr = ternary('IS_ARRAY(doc.tags)', 'FIRST(doc.tags)', 'null');
 * // Produces: 'IS_ARRAY(doc.tags) ? FIRST(doc.tags) : null'
 * ```
 */
function ternary( string $condition , mixed $trueValue = null , mixed $falseValue = null ) :string
{
    $operation = [ $condition , Char::QUESTION_MARK , $trueValue , Char::COLON , $falseValue ] ;
    return implode( Char::SPACE , $operation ) ;
}