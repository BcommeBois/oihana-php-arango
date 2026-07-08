<?php

namespace oihana\arango\db\helpers;

use oihana\arango\models\enums\filters\FilterComparator;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

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
 * @throws ValidationException If the field is not a safe attribute name
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
    // Defense in depth: the field is interpolated into `CURRENT.<field>`, so it
    // must be a safe attribute name — the sole guard on a `match` sub-field name
    // when the caller declares no `AQL::FILTERS` whitelist (never trust the path).
    assertAttributeName( $field ) ;

    // Fail-loud: only the recognised comparators (FilterComparator::__ALIAS__) have a
    // meaningful inline form (`CURRENT.<field> <op> …`). An unknown operator — a range
    // like `between`, a typo (`gte`), or a flat-only form (`contains`, `sw`, `regex`,
    // `distance`) — would otherwise silently degrade to `==` against the raw value: a
    // valid AQL that never matches, i.e. a silent `0`. The `null` sentinel makes an
    // unrecognised operator observable (getAlias otherwise defaults to `==`).
    $aqlOperator = FilterComparator::getAlias( $operator , null ) ;

    if ( $aqlOperator === null )
    {
        throw new ValidationException( sprintf
        (
            "Operator '%s' is not supported inside a `match` / array-expansion inline filter. " .
            "Supported operators: eq, ne, gt, ge, lt, le, in, nin, like, nlike, match, nmatch. " .
            "For a range, use two conditions (e.g. `ge` + `le`) instead of `between`." ,
            $operator
        ) ) ;
    }

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
