<?php

namespace oihana\arango\clients\aql ;

/**
 * Immutable representation of an AQL query and its bind parameters.
 *
 * Instances are typically obtained through the {@see aql()} helper (which
 * produces them from a string template + variadic values), or constructed
 * directly when the caller already holds a `(query, bindVars)` pair — for
 * example when the query was assembled by a query builder.
 *
 * Example:
 * ```php
 * // Built via the aql() helper:
 * $query = aql( 'FOR u IN users FILTER u.age > ? RETURN u' , 18 ) ;
 *
 * // Or built directly (typical for query-builder output):
 * $query = new AqlQuery
 * (
 *     'FOR u IN users FILTER u.age > @minAge RETURN u' ,
 *     [ 'minAge' => 18 ] ,
 * ) ;
 * ```
 *
 * @package oihana\arango\clients\aql
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class AqlQuery
{
    /**
     * @param string               $query    Raw AQL query string. Bind references use ArangoDB's `@name` (values) or `@@name` (collections) syntax.
     * @param array<string, mixed> $bindVars Map of bind name → value (without the leading `@`).
     */
    public function __construct( public string $query , public array $bindVars = [] ) {}
}
