<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\operators\logicalNot;
use function oihana\core\strings\predicates;

/**
 * Compile a `Field::WHEN` descriptor into a boolean AQL expression.
 *
 * Mirrors the recursive grammar of the flat `?filter=` DSL
 * ({@see \oihana\arango\models\traits\aql\filters\HasFilterConditions}) — so a condition
 * written for a filter reads the same here — but compiles **inline** (no bind variables),
 * because the projection layer ({@see aqlFields()}) carries none.
 *
 * Disambiguation:
 * - **string** → a truthiness leaf: `'active'` → `TO_BOOL(doc.active)`.
 * - **associative array** → an explicit leaf (see {@see buildWhenLeaf()}).
 * - **list whose first element is a logic keyword** (`and` / `or` / `not`) → a group over
 *   the remaining conditions; `not` expects exactly one condition.
 * - **list whose elements are all arrays** → an implicit `AND` group.
 * - **list of scalars** → a single leaf `[ '<attr>', '<op>'?, <value> ]`.
 *
 * Examples:
 * ```php
 * buildWhenCondition( 'active' );
 * // TO_BOOL(doc.active)
 *
 * buildWhenCondition( [ 'visibility', 'public' ] );
 * // doc.visibility == 'public'
 *
 * buildWhenCondition( [ [ 'a', 'x' ], [ 'b', 'gt', 0 ] ] );
 * // (doc.a == 'x' && doc.b > 0)
 *
 * buildWhenCondition( [ 'or', [ 'role', 'admin' ], [ 'owner', 'eq', true ] ] );
 * // (doc.role == 'admin' || doc.owner == true)
 *
 * buildWhenCondition( [ 'not', [ 'anonymized', true ] ] );
 * // !(doc.anonymized == true)
 * ```
 *
 * @param mixed  $when The condition descriptor (string, leaf, or group).
 * @param string $doc  The document reference (default: `AQL::DOC`).
 *
 * @return string The boolean AQL expression.
 *
 * @throws UnsupportedOperationException If the descriptor is malformed (empty, or a `not`
 *                                       group with the wrong arity).
 * @throws ValidationException           If a leaf attribute name is unsafe.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.3.0
 * @author Marc Alcaraz
 */
function buildWhenCondition( mixed $when , string $doc = AQL::DOC ): string
{
    // string → truthiness leaf
    if ( is_string( $when ) )
    {
        return buildWhenLeaf( [ $when ] , $doc ) ;
    }

    if ( !is_array( $when ) || $when === [] )
    {
        throw new UnsupportedOperationException( __FUNCTION__ . " failed, Field::WHEN must be a non-empty string, condition leaf, or group." ) ;
    }

    // associative → explicit leaf
    if ( !array_is_list( $when ) )
    {
        return buildWhenLeaf( $when , $doc ) ;
    }

    // list starting with a logic keyword → group
    if ( is_string( $when[0] ) && FilterLogic::includes( $when[0] ) )
    {
        $operator = array_shift( $when ) ;

        if ( $operator === FilterLogic::NOT )
        {
            if ( count( $when ) !== 1 )
            {
                throw new UnsupportedOperationException( __FUNCTION__ . " failed, the 'not' group expects exactly one condition." ) ;
            }
            return logicalNot( buildWhenCondition( $when[0] , $doc ) , true ) ;
        }

        $parts = array_map( fn( $condition ) => buildWhenCondition( $condition , $doc ) , $when ) ;
        return predicates( $parts , FilterLogic::getAlias( $operator ) , true ) ;
    }

    // list whose elements are all arrays → implicit AND group
    $allArrays = true ;
    foreach ( $when as $element )
    {
        if ( !is_array( $element ) )
        {
            $allArrays = false ;
            break ;
        }
    }

    if ( $allArrays )
    {
        $parts = array_map( fn( $condition ) => buildWhenCondition( $condition , $doc ) , $when ) ;
        return predicates( $parts , Logic::AND , true ) ;
    }

    // list of scalars → a single leaf
    return buildWhenLeaf( $when , $doc ) ;
}
