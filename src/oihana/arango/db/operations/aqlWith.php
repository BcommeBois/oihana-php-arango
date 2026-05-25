<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\Operator;
use oihana\enums\Char;

/**
 * Generates an AQL `WITH` clause for one or more collections.
 *
 * The `WITH` clause restricts the query to only access the specified collections.
 *
 * Syntax:
 * ```aql
 * WITH collection1, collection2, ...
 * ```
 *
 * Example usage:
 * ```php
 * echo aqlWith('users');              // "WITH users"
 * echo aqlWith('users', 'orders');    // "WITH users, orders"
 * echo aqlWith();                     // ""
 * ```
 *
 * @param string ...$collections List of collection names to include in the query.
 *
 * @return string The generated AQL `WITH` clause, or an empty string if none provided.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/with
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlWith( string ...$collections ) :string
{
    return ( count( $collections ) > 0 )
        ? Operator::WITH . Char::SPACE . implode( Char::COMMA . Char::SPACE , $collections )
        : Char::EMPTY ;
}