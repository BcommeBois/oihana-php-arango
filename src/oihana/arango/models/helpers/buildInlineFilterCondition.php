<?php

namespace oihana\arango\db\helpers;

use oihana\arango\models\enums\filters\FilterComparator;
use oihana\exceptions\BindException;

use function oihana\arango\db\binds\aqlBind;

/**
 * Build inline filter condition for array expansion.
 *
 * Generates an AQL condition for use within array inline filtering syntax (CURRENT.field).
 * Handles null values specially (no binding) and binds other values for security.
 *
 * @param string $field The field name (e.g., "email")
 * @param string $operator The comparison operator (e.g., "eq", "ne", "like")
 * @param mixed $value The value to compare against
 * @param array|null  &$binds Bind variables array
 *
 * @return string The inline filter condition (e.g., "CURRENT.email != null")
 *
 * @throws BindException If binding fails
 *
 * @example
 * ```php
 * // Check for non-null email
 * $condition = buildInlineFilterCondition("email", "ne", null, $binds);
 * // → "CURRENT.email != null"
 *
 * // Check for specific email
 * $condition = buildInlineFilterCondition("email", "eq", "john@doe.com", $binds);
 * // → "CURRENT.email == @bind_xxx"
 *
 * // LIKE pattern
 * $condition = buildInlineFilterCondition("email", "like", "%@gmail.com", $binds);
 * // → "CURRENT.email LIKE @bind_xxx"
 *
 * // Usage in array expansion
 * $aql = "doc.contacts[* FILTER {$condition}]";
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function buildInlineFilterCondition
(
    string $field    ,
    string $operator ,
    mixed  $value    ,
    ?array &$binds   ,
)
:string
{
    $aqlOperator = FilterComparator::getAlias( $operator ) ;

    // Build the condition
    if ( $value === null )
    {
        return "CURRENT.{$field} {$aqlOperator} null" ;
    }
    else
    {
        // For value checks, bind the value for security
        $boundValue = aqlBind( $value , $binds ) ;
        return "CURRENT.{$field} {$aqlOperator} {$boundValue}" ;
    }
}
