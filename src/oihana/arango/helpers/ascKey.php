<?php

namespace oihana\arango\helpers ;

/**
 * Returns the ascending form of a sort key in the textual sort grammar
 * (the same grammar consumed by `SortTrait::prepareSort` and the HTTP
 * `?sort=` query parameter).
 *
 * The ascending form is the key itself — no leading `-`. The helper exists
 * for **symmetry with {@see descKey()}** and to make sort intent explicit
 * at call site, avoiding magic-string concatenations.
 *
 * Do not confuse with {@see \oihana\arango\db\operations\aqlAsc()}, which
 * builds the AQL output token (`"doc.name ASC"`) — this helper produces
 * the HTTP/textual input token (`"name"`).
 *
 * @param string $key The sort field (typically a typed constant such as `Prop::CREATED`).
 *
 * @return string The ascending sort token.
 *
 * @example
 * ```php
 * use fr\bouney\enums\Prop;
 * use oihana\arango\enums\Arango;
 *
 * use function oihana\arango\helpers\ascKey;
 * use function oihana\arango\helpers\sortKeys;
 *
 * Arango::SORT => ascKey( Prop::NAME ) ;                          // 'name'
 * Arango::SORT => sortKeys( descKey( Prop::CREATED ) , ascKey( Prop::NAME ) ) ;
 * ```
 *
 * @see descKey()  Descending counterpart.
 * @see sortKeys() Joins multiple sort tokens with a comma.
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\helpers
 */
function ascKey( string $key ) :string
{
    return $key ;
}
