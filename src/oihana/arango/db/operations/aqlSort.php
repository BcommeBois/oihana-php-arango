<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\Operation;
use oihana\enums\Char;

use function oihana\core\strings\compile;

/**
 * Builds an AQL `SORT` clause from a string or an array of sort expressions.
 *
 * This helper assembles a valid `SORT` operation for AQL queries.
 * It accepts a single sort expression or multiple ones (as an array),
 * and automatically joins them with commas when needed.
 *
 * Each expression can be generated manually or using helpers like
 * {@see aqlAsc()} and {@see aqlDesc()}.
 *
 * ### Example: with a single key
 * ```php
 * echo aqlSort('user.age ASC');
 * // → "SORT user.age ASC"
 * ```
 *
 * ### Example: with multiple expressions
 * ```php
 * echo aqlSort([
 *     aqlAsc('score', 'player'),
 *     aqlDesc('createdAt', 'doc')
 * ]);
 * // → "SORT player.score ASC, doc.createdAt DESC"
 * ```
 *
 * ### Example: empty or null input
 * ```php
 * echo aqlSort(null);     // → ""
 * echo aqlSort([]);       // → ""
 * echo aqlSort('');       // → ""
 * ```
 *
 * @param string|array|null $expression
 *     The sort expression(s). Can be:
 *     - a string (`"age ASC"`)
 *     - an array of expressions (`["a ASC", "b DESC"]`)
 *     - `null` or empty string for no output
 *
 * @return string
 *     The formatted `SORT` clause, or an empty string if no expression is provided.
 *
 * @see aqlAsc()  For ascending order helpers.
 * @see aqlDesc() For descending order helpers.
 * @see https://docs.arangodb.com/stable/aql/fundamentals/sorting
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlSort( string|array|null $expression ) :string
{
    if( is_array( $expression ) )
    {
        $expression = compile( $expression , Char::COMMA . Char::SPACE ) ;
    }
    return !is_null( $expression ) && $expression != Char::EMPTY ? Operation::SORT . Char::SPACE . $expression : Char::EMPTY ;
}