<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use function oihana\arango\db\binds\aqlBind;

/**
 * Provides helpers to build AQL `LIMIT` clauses for ArangoDB queries,
 * supporting **offsets**, **parameter binding**, and **dynamic query generation**.
 *
 * The `LIMIT` clause in ArangoDB is used to:
 * - Restrict the number of results returned by a query.
 * - Optionally skip a number of documents using an **offset**.
 * - Use **bind parameters** instead of hardcoded values to improve performance
 * and prevent query plan cache invalidation.
 **AQL Syntax**
 * ```aql
 * LIMIT <count>
 * LIMIT <offset>, <count>
 * ```
 **Why use bind parameters for LIMIT/OFFSET?**
 *
 * Using placeholders like `@limit` and `@offset` allows ArangoDB to reuse
 * the same query execution plan instead of recompiling it each time.
 * This is particularly efficient when paginating through large datasets.
 *
 * Examples:
 * ```php
 * // Simple limit
 * echo aqlLimit(10);
 * // LIMIT 10
 *
 * // Limit with offset
 * echo aqlLimit(10, 5);
 * // LIMIT 5, 10
 *
 * // Limit with bound parameters
 * $binds = [];
 * echo aqlLimit(10, 5, $binds);
 * // LIMIT @offset, @limit
 * // $binds = [ 'limit' => 10 , 'offset' => 5 ]
 * ```
 *
 * @param int $limit  Maximum number of results to return. Must be > 0 to generate a clause.
 * @param int $offset Number of results to skip before starting to return results (default 0).
 * @param array|null $binds Optional reference to a binds array for parameterized queries.
 *
 * @return string AQL LIMIT clause string, or empty string if $limit <= 0.
 *
 * @throws BindException If parameter binding fails.
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlLimit
(
    int     $limit ,
    int     $offset = 0 ,
    ?array &$binds  = null
)
:string
{
    if ( $limit <= 0 || $offset < 0 )
    {
        return Char::EMPTY ;
    }

    if( is_array( $binds ) )
    {
        $limit = aqlBind( $limit  , $binds , AQL::LIMIT ) ;
        if( $offset > 0 )
        {
            $offset = aqlBind( $offset , $binds , AQL::OFFSET ) ;
        }
    }

    if( $offset > 0 )
    {
        return Operation::LIMIT . Char::SPACE . $offset . Char::COMMA . Char::SPACE . $limit ;
    }

    return Operation::LIMIT . Char::SPACE . $limit ;
}