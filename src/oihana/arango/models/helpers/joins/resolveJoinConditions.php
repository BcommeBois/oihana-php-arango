<?php

namespace oihana\arango\models\helpers\joins;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;

/**
 * The extra predicates a join definition declares over the documents it joins —
 * `Arango::CONDITIONS` — compiled against the loop variable of the join.
 *
 * 🔑 **One reading for every path that walks a join** : the projection
 * ({@see buildJoinSubquery()}) and the filter that goes through the same join
 * (`HasHierarchicalFilter`). A filter that ignored them retained a parent by a
 * document the projection masks — the very read by ricochet the conditions exist to
 * close.
 *
 * Two shapes : a plain **array** of AQL predicate strings, or a **callable** returning
 * one. The callable receives three arguments, always — the join loop variable, the
 * parent document reference, and the request-level init ; PHP discards the surplus
 * handed to a userland callable, so a one- or two-parameter closure keeps working.
 *
 * @param array  $definition The join definition (reads `AQL::CONDITIONS`).
 * @param string $joinRef    The AQL variable of the joined document.
 * @param string $parentRef  The AQL variable of the parent document.
 * @param array  $init       The request-level init.
 *
 * @return array<int,string> The predicates — empty when nothing is declared.
 *
 * @throws UnexpectedValueException When the declaration is not an array, nor returns one.
 *
 * @example
 * ```php
 * resolveJoinConditions( [ AQL::CONDITIONS => fn( $join ) => [ $join . '.active == true' ] ] , 'doc_join' , 'doc' ) ;
 * // [ 'doc_join.active == true' ]
 * ```
 *
 * @package oihana\arango\models\helpers\joins
 * @since 1.11.0
 * @author Marc Alcaraz
 */
function resolveJoinConditions( array $definition , string $joinRef , string $parentRef , array $init = [] ) :array
{
    if ( !isset( $definition[ AQL::CONDITIONS ] ) )
    {
        return [] ;
    }

    $declared   = $definition[ AQL::CONDITIONS ] ;
    $conditions = is_callable( $declared ) ? $declared( $joinRef , $parentRef , $init ) : $declared ;

    if ( !is_array( $conditions ) )
    {
        throw new UnexpectedValueException
        (
            __FUNCTION__ . ' expected $conditions to be an array, ' . gettype( $conditions ) . ' given'
        ) ;
    }

    return $conditions ;
}
