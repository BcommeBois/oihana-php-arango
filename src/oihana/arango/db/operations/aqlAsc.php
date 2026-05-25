<?php

namespace oihana\arango\db\operations;

use oihana\enums\Order;
use oihana\enums\Char;

use function oihana\core\strings\key;

/**
 * Builds an ascending AQL `SORT` expression for the given attribute key.
 *
 * This helper simplifies the creation of `SORT` clauses by combining the
 * provided key (and optional prefix) with the `ASC` order keyword.
 *
 * The resulting string can be directly injected into an AQL statement or
 * composed with other expressions (e.g., using `aqlSort()`).
 *
 * ### Example: basic usage
 * ```php
 * echo aqlAsc('age');
 * // → "age ASC"
 * ```
 *
 * ### Example: with prefix
 * ```php
 * echo aqlAsc('name', 'u');
 * // → "u.name ASC"
 * ```
 *
 * ### Example: combined in a SORT clause
 * ```php
 * echo 'SORT ' . aqlAsc('score', 'player');
 * // → "SORT player.score ASC"
 * ```
 *
 * @param string $key
 * The attribute name to sort by (e.g., `'age'`, `'createdAt'`, `'score'`).
 *
 * @param string|null $prefix
 * Optional variable or collection prefix (e.g., `'u'` for `"u.age"`).
 *
 * @return string
 * The formatted ascending sort expression, e.g. `"doc.name ASC"`.
 *
 * @see aqlDesc() For descending order.
 * @see https://docs.arangodb.com/stable/aql/fundamentals/sorting
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlAsc( string $key , ?string $prefix = null ):string
{
    return key( $key , $prefix ) . Char::SPACE . Order::ASC ;
}