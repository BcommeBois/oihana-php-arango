<?php

namespace oihana\arango\db\operators;

use oihana\enums\Char;

/**
 * Generates a shorthand nullish ternary expression.
 *
 * This is a variant of the ternary operator that only requires a condition and a default value.
 * The expression evaluates the `$condition`; if it is "truthy", the value is returned,
 * otherwise the `$defaultValue` is returned.
 *
 * Example:
 * ```php
 * nullish('u.value', 'default') // Produces: "u.value ? : default"
 * ```
 *
 * Useful for building concise AQL expressions where you want to provide a fallback
 * if a field is null, missing, or evaluates to false.
 *
 * @param string $condition Boolean expression to evaluate (e.g. a field reference)
 * @param mixed|null $defaultValue Value to return if the condition is false
 * @return string A string representing the nullish ternary expression
 */
function nullish( string $condition , mixed $defaultValue = null ) :string
{
    $operation = [ $condition , Char::QUESTION_MARK , Char::COLON , $defaultValue ] ;
    return implode( Char::SPACE , $operation ) ;
}