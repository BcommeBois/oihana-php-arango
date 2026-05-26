<?php

namespace oihana\arango\helpers ;

use oihana\enums\Char;

/**
 * Returns the descending form of a sort key in the textual sort grammar
 * (the same grammar consumed by `SortTrait::prepareSort` and the HTTP
 * `?sort=` query parameter).
 *
 * The descending form is the key prefixed with `-`. This helper centralizes
 * that convention, avoiding magic-string concatenations like
 * `'-' . Prop::CREATED`.
 *
 * Do not confuse with {@see \oihana\arango\db\operations\aqlDesc()}, which
 * builds the AQL output token (`"doc.name DESC"`) — this helper produces
 * the HTTP/textual input token (`"-name"`).
 *
 * @param string $key The sort field (typically a typed constant such as `Prop::CREATED`).
 *
 * @return string The descending sort token, prefixed with `-`.
 *
 * @example
 * ```php
 * use App\Enums\Prop;
 * use oihana\arango\db\enums\AQL;
 * use oihana\arango\enums\Arango;
 *
 * use function oihana\arango\helpers\descKey;
 * use function oihana\arango\helpers\sortKeys;
 *
 * AQL::SORT_DEFAULT => descKey( Prop::CREATED ) ;                            // '-created'
 * Arango::SORT      => descKey( Prop::CREATED ) ;                            // '-created'
 * Arango::SORT      => sortKeys( descKey( Prop::CREATED ) , Prop::NAME ) ;   // '-created,name'
 * Arango::SORT      => sortKeys( descKey( Prop::CREATED ) , descKey( Prop::NAME ) ) ; // '-created,-name'
 * ```
 *
 * @see ascKey()   Ascending counterpart.
 * @see sortKeys() Joins multiple sort tokens with a comma.
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\helpers
 */
function descKey( string $key ) :string
{
    return Char::HYPHEN . $key ;
}
