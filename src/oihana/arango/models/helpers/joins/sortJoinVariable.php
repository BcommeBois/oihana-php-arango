<?php

namespace oihana\arango\models\helpers\joins;

use oihana\arango\db\enums\AQL;
use oihana\enums\Order;
use org\schema\constants\Schema;

use function oihana\arango\db\operations\aqlAsc;
use function oihana\arango\db\operations\aqlDesc;
use function oihana\arango\db\operations\aqlSort;

/**
 * Generates the internal AQL 'SORT' clause for an edge variable subquery.
 *
 * This helper method interprets a flexible sort definition and constructs
 * the appropriate AQL 'SORT' expression.
 *
 * - If $definition is an array: Looks for `AQL::SORT` (property) and `AQL::ORDER` (ASC/DESC)
 * and sorts by the *vertex* property.
 * - If $definition is a string (legacy): Sorts by that string as the property on the *vertex* in ASC order.
 * - If $definition is null (or `AQL::SORT` is not set in the array):
 * Sorts by the $defaultProperty (e.g., 'created') on the *edge* in DESC order.
 *
 * @param array|string|null $definition       The sort configuration. Typically the `$definition` array from getEdgeVariable.
 * @param string            $vertexRef        The internal AQL vertex variable reference.
 * @param string            $edgeRef          The internal AQL edge variable reference.
 * @param string            $defaultProperty  The fallback property to sort by (default: 'created').
 *
 * @return string The generated AQL 'SORT' clause (e.g., "SORT v_myVar_collectionName.name ASC").
 *
 * @example
 * Assume the following constant values for the examples:
 * - AQL::SORT = 'sort'
 * - AQL::ORDER = 'order'
 * - Order::DESC = 'DESC'
 * - Order::ASC = 'ASC'
 * - Schema::CREATED = 'created'
 * - AQL::EDGE_PREFIX = 'e_'
 * - AQL::VERTEX_PREFIX = 'v_'
 *
 * ### Case 1: Default sort (null definition)
 *
 * Sorts by 'created' on the *edge* (e_) in DESC order.
 *
 * ```php
 * echo sortEdgeVariable( null , 'friends_rel');
 * // Output: "SORT e_friends_rel.created DESC"
 * ```
 * ### Case 2: Legacy string sort (string definition)
 *
 * Sorts by 'name' on the *vertex* (v_) in ASC order.
 *
 * ```php
 * echo sortEdgeVariable( 'name' , 'friends_rel');
 * // Output: "SORT v_friends_rel.name ASC"
 * ```
 *
 * ### Case 3: Array definition (DESC)
 *
 * Sorts by 'age' on the *vertex* (v_) in DESC order.
 *
 * ```php
 * $definition =
 * [
 *     AQL::SORT  => 'age',
 *     AQL::ORDER => Order::DESC
 * ];
 * echo sortEdgeVariable( $definition, 'friends_rel' );
 * // Output: "SORT v_friends_rel.age DESC"
 * ```
 *
 * ### Case 4: Array definition (ASC)
 *
 * Sorts by 'lastName' on the *vertex* (v_) in ASC order.
 *
 * ```php
 * $definition = [ AQL::SORT => 'lastName' ]; // AQL::ORDER defaults to ASC
 * echo sortEdgeVariable( $definition , 'friends_rel');
 * // Output: "SORT v_friends_rel.lastName ASC"
 * ```
 *
 * ###  Case 5: Array definition missing 'sort' key ---
 *
 * Falls back to default sort (Case 1).
 *
 * ```php
 * $def5 = [ AQL::ORDER => Order::DESC ];
 * echo sortEdgeVariable($def5, 'friends_rel');
 * // Output: "SORT e_friends_rel.created DESC"
 * * ```
 *
 */
function sortJoinVariable
(
    null|array|string $definition,
    string            $docRef          = AQL::DOC_JOIN,
    string            $defaultProperty = Schema::_KEY
)
: string
{
    $isArray = is_array( $definition ) ;
    $order   = Order::ASC;

    if ( $isArray )
    {
        $order      = ( $definition[ AQL::ORDER ] ?? null ) === Order::DESC ? Order::DESC : Order::ASC ;
        $definition = $definition[ AQL::SORT ] ?? null ;
    }

    $sort = !is_string( $definition ) ? null : $definition ;

    if ( is_null( $sort ) )
    {
        return aqlSort( aqlDesc( $defaultProperty, $docRef ) );
    }

    return match ( $order )
    {
        Order::DESC => aqlSort( aqlDesc ( $sort , $docRef ) ) ,
        default     => aqlSort( aqlAsc  ( $sort , $docRef ) ) ,
    };
}