<?php

namespace oihana\arango\models\helpers;

/**
 * Normalises a `sortable` whitelist into the canonical `urlKey => fieldPath`
 * associative map consumed by {@see \oihana\arango\models\traits\aql\SortTrait::prepareSort()}.
 *
 * Three notations are accepted and may be **mixed within the same array**:
 *
 * - **Associative (legacy)** — `urlKey => fieldPath`. The public `?sort=` token
 *   (the array *key*) resolves to the AQL field (the *value*). The value may be a
 *   string or an array path (`[ 'address', 'city' ]` → `address.city`). This is the
 *   historical form and is returned untouched.
 * - **Indexed shorthand** — a plain string `fieldName`. The token equals the field
 *   (`urlKey === fieldPath`), so the redundant `field => field` map is avoided.
 * - **Indexed alias** — a single-pair array `[ urlKey => fieldPath ]`, for the rare
 *   case where the public token differs from the AQL field (`?sort=name` → `givenName`
 *   is written `[ 'name' => 'givenName' ]`). The pair direction is always
 *   `[ urlKey => fieldPath ]`, identical to the associative form.
 *
 * The pair direction `[ urlKey => fieldPath ]` is preserved across every notation, so
 * the legacy associative map and the new indexed forms speak the same language.
 *
 * Robustness rules:
 * - `null` (open mode, no whitelist) is returned as-is.
 * - In an indexed alias, an entry keyed by a non-string (a pure list such as
 *   `[ 'address', 'city' ]` with no token) is dropped — an alias *must* carry a token.
 * - An indexed value that is neither a string nor an array contributes nothing.
 *
 * The function is **idempotent**: re-running it on an already-canonical map returns the
 * same map, so it is safe to apply at construction even when the input is already legacy.
 *
 * @param array<int|string, mixed>|null $sortable The raw `sortable` definition, in any of the three notations.
 *
 * @return array<string, string|array<int, string>>|null The canonical `urlKey => fieldPath` map, or `null` when `$sortable` is `null`.
 *
 * @example
 * ```php
 * use function oihana\arango\models\helpers\normalizeSortable;
 *
 * // Associative (legacy) — returned untouched.
 * normalizeSortable( [ 'created' => 'created' , 'name' => 'givenName' ] ) ;
 * // [ 'created' => 'created' , 'name' => 'givenName' ]
 *
 * // Indexed shorthand — token equals field.
 * normalizeSortable( [ '_from' , '_to' , 'created' ] ) ;
 * // [ '_from' => '_from' , '_to' => '_to' , 'created' => 'created' ]
 *
 * // Hybrid — a shorthand list with one alias.
 * normalizeSortable( [ [ 'name' => 'givenName' ] , '_to' , 'created' ] ) ;
 * // [ 'name' => 'givenName' , '_to' => '_to' , 'created' => 'created' ]
 *
 * // Open mode — passed through.
 * normalizeSortable( null ) ; // null
 * ```
 *
 * @package oihana\arango\models\helpers
 * @since   1.5.0
 * @author  Marc Alcaraz
 */
function normalizeSortable( ?array $sortable ): ?array
{
    if ( $sortable === null )
    {
        return null ;
    }

    $result = [] ;

    foreach ( $sortable as $key => $value )
    {
        if ( is_string( $key ) )
        {
            // Associative entry (legacy): urlKey => fieldPath (value kept verbatim).
            $result[ $key ] = $value ;
        }
        elseif ( is_array( $value ) )
        {
            // Indexed alias: [ urlKey => fieldPath ]. A non-string key (a pure list
            // with no token) is not an alias and is dropped.
            foreach ( $value as $alias => $field )
            {
                if ( is_string( $alias ) )
                {
                    $result[ $alias ] = $field ;
                }
            }
        }
        elseif ( is_string( $value ) )
        {
            // Indexed shorthand: the token equals the field.
            $result[ $value ] = $value ;
        }
    }

    return $result ;
}
