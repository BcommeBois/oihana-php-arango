<?php

namespace oihana\arango\db\helpers;

use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterMatch;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\enums\Boolean;
use oihana\exceptions\BindException;

use RuntimeException;

/**
 * Build combined inline filter conditions for array expansion.
 *
 * Supports multiple logic operators:
 * - "all": ALL conditions must be true (AND logic)
 * - "any": AT LEAST ONE condition must be true (OR logic)
 * - "none": NO condition must be true (NOT logic)
 *
 * Supports two formats:
 *
 * 1. Simple object (all conditions are "eq" with AND logic):
 *    {"propertyID": "X", "value": true}
 *    → CURRENT.propertyID == "X" AND CURRENT.value == true
 *
 * 2. Explicit array with operators and logic:
 *    {"all": [
 *      {"key": "propertyID", "op": "eq", "val": "X"},
 *      {"key": "value", "op": "ne", "val": null}
 *    ]}
 *    → CURRENT.propertyID == "X" AND CURRENT.value != null
 *
 *    {"any": [
 *      {"key": "email", "op": "ne", "val": null},
 *      {"key": "telephone", "op": "ne", "val": null}
 *    ]}
 *    → CURRENT.email != null OR CURRENT.telephone != null
 *
 *    {"none": [
 *      {"key": "status", "op": "eq", "val": "deleted"},
 *      {"key": "archived", "op": "eq", "val": true}
 *    ]}
 *    → !(CURRENT.status == "deleted" OR CURRENT.archived == true)
 *
 * @param array      $match         Match configuration
 * @param array|null &$binds        Bind variables array
 * @param array      $allowedFields Optional: List of allowed field names for validation
 * @param mixed      $alt           Optional `alt` transformation applied to EVERY sub-field condition (field + value).
 *
 * @return string The combined inline filter condition
 *
 * @throws BindException If binding fails
 * @throws UnsupportedOperationException If an alt chain is invalid
 *
 * @example
 * ```php
 * // ALL logic (AND)
 * $match = [
 *     "all" => [
 *         ["key" => "propertyID", "op" => "eq", "val" => "X"],
 *         ["key" => "value", "op" => "eq", "val" => true]
 *     ]
 * ];
 * $condition = buildCombinedInlineFilter($match, $binds);
 * // → "CURRENT.propertyID == @bind1 && CURRENT.value == @bind2"
 *
 * // ANY logic (OR)
 * $match = [
 *     "any" => [
 *         ["key" => "email", "op" => "ne", "val" => null],
 *         ["key" => "telephone", "op" => "ne", "val" => null]
 *     ]
 * ];
 * $condition = buildCombinedInlineFilter($match, $binds);
 * // → "CURRENT.email != null || CURRENT.telephone != null"
 *
 * // NONE logic (NOT)
 * $match = [
 *     "none" => [
 *         ["key" => "archived", "op" => "eq", "val" => true]
 *     ]
 * ];
 * $condition = buildCombinedInlineFilter($match, $binds);
 * // → "!(CURRENT.archived == @bind1)"
 *
 * // Simple syntax (defaults to ALL)
 * $match = ["propertyID" => "X", "value" => true];
 * $condition = buildCombinedInlineFilter($match, $binds);
 * // → "CURRENT.propertyID == @bind1 && CURRENT.value == @bind2"
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function buildCombinedInlineFilter
(
    array  $match  ,
    ?array &$binds ,
    array  $allowedFields = [] ,
    mixed  $alt           = null ,
)
: string
{
    // Detect logic operator and conditions

    $logic      = null ;
    $conditions = []   ;

    // Check for explicit logic operators
    if ( isset( $match[ FilterMatch::ALL ] ) && is_array( $match[ FilterMatch::ALL ] ) )
    {
        $logic      = FilterMatch::ALL ;
        $conditions = $match[ FilterMatch::ALL ] ;
    }
    else if ( isset( $match[ FilterMatch::ANY ] ) && is_array( $match[ FilterMatch::ANY ] ) )
    {
        $logic      = FilterMatch::ANY ;
        $conditions = $match[ FilterMatch::ANY ] ;
    }
    else if ( isset( $match[ FilterMatch::NONE ] ) && is_array( $match[ FilterMatch::NONE ] ) )
    {
        $logic      = FilterMatch::NONE ;
        $conditions = $match[ FilterMatch::NONE ] ;
    }
    else
    {
        // Simple object format (all are "eq" with AND logic)
        $logic = FilterMatch::ALL ;
        $parts = [] ;

        foreach ( $match as $key => $value )
        {
            if ( !empty( $allowedFields ) && !isset( $allowedFields[ $key ] ) )
            {
                throw new RuntimeException
                (
                    "Field '$key' is not allowed in match filter. " .
                    "Allowed fields: " . implode( ', ' , array_keys( $allowedFields ) )
                ) ;
            }

            $parts[] = buildInlineFilterCondition
            (
                field    : $key                  ,
                operator : FilterComparator::EQ  ,
                value    : $value                ,
                binds  : $binds                ,
                alt    : $alt                  ,
            ) ;
        }

        return implode( ' && ' , $parts ) ;
    }

    // Build individual conditions
    $parts = [] ;

    foreach ( $conditions as $condition )
    {
        $key      = $condition[ FilterParam::KEY ] ?? null ;
        $operator = $condition[ FilterParam::OP  ] ?? FilterComparator::EQ ;
        $value    = $condition[ FilterParam::VAL ] ?? null ;

        if ( !$key )
        {
            continue ;
        }

        // Validate field if allowedFields is provided
        if ( !empty( $allowedFields ) && !isset( $allowedFields[ $key ] ) )
        {
            throw new RuntimeException
            (
                "Field '$key' is not allowed in match filter. " .
                "Allowed fields: " . implode( ', ' , array_keys( $allowedFields ) )
            ) ;
        }

        $parts[] = buildInlineFilterCondition
        (
            field    : $key      ,
            operator : $operator ,
            value    : $value    ,
            binds  : $binds    ,
            alt    : $alt      ,
        ) ;
    }

    if ( empty( $parts ) )
    {
        return Boolean::TRUE ; // Fallback
    }

    // Combine with appropriate logic
    return match( $logic )
    {
        FilterMatch::ANY   => implode( ' || ' , $parts ) ,
        FilterMatch::NONE  => '!(' . implode( ' || ' , $parts ) . ')' ,
        default => implode( ' && ' , $parts ) // 'all'
    } ;
}