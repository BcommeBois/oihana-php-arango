<?php

namespace oihana\arango\db\helpers;

use oihana\arango\models\enums\filters\FilterComparator;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\binds\aqlBind;

/**
 * Build inline filter condition for array expansion.
 *
 * Generates an AQL condition for use within array inline filtering syntax (CURRENT.field).
 * Handles null values specially (no binding) and binds other values for security.
 *
 * An optional `$alt` chain wraps the compared field (left, `CURRENT.<field>`)
 * and/or the bound value (right) — same `alt:{key,val}` / `val:true` mirror
 * vocabulary as the flat filters — so case-insensitive matches work inside the
 * array expansion (`LOWER(CURRENT.email) == LOWER(@v)`).
 *
 * @param string $field The field name (e.g., "email")
 * @param string $operator The comparison operator (e.g., "eq", "ne", "like")
 * @param mixed $value The value to compare against
 * @param array|null  &$binds Bind variables array
 * @param mixed $alt The `alt` transformation (string/list = field only, object `{key,val}` = both sides); null for none.
 *
 * @return string The inline filter condition (e.g., "CURRENT.email != null")
 *
 * @throws BindException If binding fails
 * @throws UnsupportedOperationException If an alt chain is invalid
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
 * // Case-insensitive, both sides
 * $condition = buildInlineFilterCondition("email", "eq", "JOHN@DOE.COM", $binds, ['key'=>'lower','val'=>true]);
 * // → "LOWER(CURRENT.email) == LOWER(@bind_xxx)"
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
    mixed  $alt      = null ,
)
:string
{
    $aqlOperator = FilterComparator::getAlias( $operator ) ;

    // `alt` wraps the compared field (left) and/or the bound value (right).
    [ $keyChain , $valChain ] = resolveAltSides( $alt ) ;
    $left = alterExpression( "CURRENT.{$field}" , $keyChain ) ;

    // Build the condition
    if ( $value === null )
    {
        return "{$left} {$aqlOperator} null" ;
    }
    else
    {
        // For value checks, bind the value for security
        $boundValue = alterExpression( aqlBind( $value , $binds ) , $valChain ) ;
        return "{$left} {$aqlOperator} {$boundValue}" ;
    }
}
