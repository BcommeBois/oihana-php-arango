<?php

namespace oihana\arango\db\operations;

use oihana\enums\Order;
use oihana\enums\Char;

use function oihana\core\strings\key;

/**
 * Builds an descending AQL `SORT` expression for the given attribute key.
 *
 * This helper simplifies the creation of `SORT` clauses by combining the
 * provided key (and optional prefix) with the `DESC` order keyword.
 *
 * The resulting string can be directly injected into an AQL statement or
 * composed with other expressions (e.g., using `aqlSort()`).
 *
 * ### Example: basic usage
 * ```php
 * echo aqlDesc('age');
 * // → "age DESC"
 * ```
 *
 * ### Example: with prefix
 * ```php
 * echo aqlDesc('name', 'u');
 * // → "u.name DESC"
 * ```
 *
 * ### Example: combined in a SORT clause
 * ```php
 * echo 'SORT ' . aqlDesc('score', 'player');
 * // → "SORT player.score DESC"
 * ```
 *
 * @param string $key
 * The attribute name to sort by (e.g., `'age'`, `'createdAt'`, `'score'`).
 *
 * @param string|null $prefix
 * Optional variable or collection prefix (e.g., `'u'` for `"u.age"`).
 *
 * @return string
 * The formatted descending sort expression, e.g. `"doc.name DESC"`.
 *
 * @see aqlAsc() For ascending order.
 * @see https://docs.arangodb.com/stable/aql/fundamentals/sorting
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlDesc( string $key , ?string $prefix = null ):string
{
    return key( $key , $prefix ) . Char::SPACE . Order::DESC ;
}