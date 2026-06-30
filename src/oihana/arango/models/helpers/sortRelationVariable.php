<?php

namespace oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use oihana\enums\Order;
use org\schema\constants\Schema;

use function oihana\arango\db\operations\aqlAsc;
use function oihana\arango\db\operations\aqlDesc;
use function oihana\arango\db\operations\aqlSort;

/**
 * Generates the internal AQL 'SORT' clause for a relation sub-query (edge or join).
 *
 * This helper interprets a flexible sort definition and constructs the appropriate
 * AQL 'SORT' expression, shared by {@see sortEdgeVariable()} and {@see sortJoinVariable()}.
 * The only thing those two wrappers customize is which variable reference carries the
 * sort property and which one carries the default fallback property:
 *
 * - An **explicit** sort property (string definition, or `AQL::SORT` in an array) is
 *   sorted on `$sortRef` (the *vertex* for an edge, the *join document* for a join).
 * - The **fallback** (no `AQL::SORT` provided) sorts by `$defaultProperty` on
 *   `$defaultRef` in DESC order (the *edge* for an edge relation, the same join
 *   document for a join).
 *
 * @param array|string|null $definition      The sort configuration (a `$definition` array, a legacy string, or null).
 * @param string            $sortRef         The AQL variable reference carrying an explicit sort property.
 * @param string            $defaultRef      The AQL variable reference carrying the fallback default property.
 * @param string            $defaultProperty The fallback property to sort by (default: 'created').
 *
 * @return string The generated AQL 'SORT' clause.
 *
 * @example
 * ```php
 * // Explicit sort on the sortRef (ASC by default)
 * echo sortRelationVariable( 'name' , 'v' , 'e' );          // SORT v.name ASC
 *
 * // Explicit sort with order
 * echo sortRelationVariable( [ AQL::SORT => 'age' , AQL::ORDER => Order::DESC ] , 'v' , 'e' ); // SORT v.age DESC
 *
 * // Fallback on the defaultRef in DESC
 * echo sortRelationVariable( null , 'v' , 'e' );            // SORT e.created DESC
 * ```
 */
function sortRelationVariable
(
    null|array|string $definition ,
    string            $sortRef ,
    string            $defaultRef ,
    string            $defaultProperty = Schema::CREATED
)
: string
{
    $order = Order::ASC ;

    if ( is_array( $definition ) )
    {
        $order      = ( $definition[ AQL::ORDER ] ?? null ) === Order::DESC ? Order::DESC : Order::ASC ;
        $definition = $definition[ AQL::SORT ] ?? null ;
    }

    $sort = is_string( $definition ) ? $definition : null ;

    if ( $sort === null )
    {
        return aqlSort( aqlDesc( $defaultProperty , $defaultRef ) ) ;
    }

    return match ( $order )
    {
        Order::DESC => aqlSort( aqlDesc( $sort , $sortRef ) ) ,
        default     => aqlSort( aqlAsc ( $sort , $sortRef ) ) ,
    };
}
