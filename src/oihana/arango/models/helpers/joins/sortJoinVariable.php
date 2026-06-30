<?php

namespace oihana\arango\models\helpers\joins;

use oihana\arango\db\enums\AQL;
use org\schema\constants\Schema;

use function oihana\arango\models\helpers\sortRelationVariable;

/**
 * Generates the internal AQL 'SORT' clause for a join variable subquery.
 *
 * This helper interprets a flexible sort definition and constructs the appropriate
 * AQL 'SORT' expression. Unlike an edge relation, a join has a single document
 * variable (`$docRef`), so both the explicit sort property and the default fallback
 * target that same reference.
 *
 * - If $definition is an array: looks for `AQL::SORT` (property) and `AQL::ORDER` (ASC/DESC)
 *   and sorts by that property on the join document.
 * - If $definition is a string (legacy): sorts by that string as the property on the
 *   join document in ASC order.
 * - If $definition is null (or `AQL::SORT` is not set in the array): sorts by the
 *   $defaultProperty (e.g. '_key') on the join document in DESC order.
 *
 * @param array|string|null $definition       The sort configuration. Typically the `$definition` array from buildJoinVariable.
 * @param string            $docRef           The internal AQL join-document variable reference (default: 'doc_join').
 * @param string            $defaultProperty  The fallback property to sort by (default: '_key').
 *
 * @return string The generated AQL 'SORT' clause.
 *
 * @example
 * ```php
 * // Case 1: default sort (null definition) — '_key' DESC on the join document.
 * echo sortJoinVariable( null , 'doc_join' );
 * // Output: "SORT doc_join._key DESC"
 *
 * // Case 2: legacy string sort — 'name' ASC on the join document.
 * echo sortJoinVariable( 'name' , 'doc_join' );
 * // Output: "SORT doc_join.name ASC"
 *
 * // Case 3: array definition (DESC).
 * echo sortJoinVariable( [ AQL::SORT => 'age' , AQL::ORDER => Order::DESC ] , 'doc_join' );
 * // Output: "SORT doc_join.age DESC"
 *
 * // Case 4: array definition without 'sort' key — falls back to default (Case 1).
 * echo sortJoinVariable( [ AQL::ORDER => Order::DESC ] , 'doc_join' );
 * // Output: "SORT doc_join._key DESC"
 * ```
 */
function sortJoinVariable
(
    null|array|string $definition,
    string            $docRef          = AQL::DOC_JOIN,
    string            $defaultProperty = Schema::_KEY
)
: string
{
    // A join has a single document reference, so the explicit sort and the default fallback share it.
    return sortRelationVariable( $definition , $docRef , $docRef , $defaultProperty ) ;
}