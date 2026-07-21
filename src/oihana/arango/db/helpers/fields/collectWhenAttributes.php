<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\models\enums\filters\FilterLogic;
use oihana\arango\models\enums\filters\FilterParam;

/**
 * Collects the document attribute names **read** by a {@see \oihana\arango\enums\Field::WHEN}
 * (or {@see \oihana\arango\enums\Field::WHERE}) condition, mirroring the grammar
 * compiled by {@see buildWhenCondition()} — a truthiness string, an explicit leaf
 * (list `[ attr, op, val ]` or associative `[ FilterParam::KEY => …, … ]`), and the
 * `and` / `or` / `not` / implicit-AND groups.
 *
 * Only the **left (attribute) side** of each leaf is returned — the compared value is
 * a bound literal and reads nothing. A left side that is not a plain attribute name
 * (an {@see \oihana\arango\db\binds\AqlBindReference}, a runtime bind) is skipped: it
 * references no document field, so there is nothing to gate.
 *
 * Used by {@see conditionReadsDeniedField()} to enforce that a conditional projection
 * cannot read a field the caller may not read (no inference oracle through a condition).
 *
 * @param mixed $when The `Field::WHEN` / `Field::WHERE` payload.
 *
 * @return array<int,string> The attribute names read by the condition (possibly empty).
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\db\helpers\fields
 * @since   1.6.0
 */
function collectWhenAttributes( mixed $when ) : array
{
    // string → a truthiness leaf on that attribute
    if ( is_string( $when ) )
    {
        return [ $when ] ;
    }

    if ( !is_array( $when ) || $when === [] )
    {
        return [] ;
    }

    // associative → explicit leaf; the attribute lives under FilterParam::KEY
    if ( !array_is_list( $when ) )
    {
        $attr = $when[ FilterParam::KEY ] ?? null ;
        return is_string( $attr ) ? [ $attr ] : [] ; // AqlBindReference / null → nothing to gate
    }

    // list starting with a logic keyword → group; recurse on the operands
    if ( is_string( $when[0] ) && FilterLogic::includes( $when[0] ) )
    {
        $attrs = [] ;
        foreach ( array_slice( $when , 1 ) as $operand )
        {
            $attrs = array_merge( $attrs , collectWhenAttributes( $operand ) ) ;
        }
        return $attrs ;
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
        $attrs = [] ;
        foreach ( $when as $condition )
        {
            $attrs = array_merge( $attrs , collectWhenAttributes( $condition ) ) ;
        }
        return $attrs ;
    }

    // list of scalars → a single leaf; the attribute is the first element
    $attr = $when[0] ?? null ;
    return is_string( $attr ) ? [ $attr ] : [] ; // AqlBindReference / non-string → nothing to gate
}
