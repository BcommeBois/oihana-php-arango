<?php

namespace oihana\arango\helpers ;

use oihana\enums\Char;

use function oihana\core\strings\compile;

/**
 * Composes a comma-separated sort expression in the textual sort grammar
 * (the same grammar consumed by `SortTrait::prepareSort` and the HTTP
 * `?sort=` query parameter).
 *
 * Each argument is a sort token, typically produced by {@see ascKey()}
 * or {@see descKey()} but plain field constants are accepted as well
 * (an unprefixed key implies ascending order).
 *
 * Null and empty tokens are skipped via {@see \oihana\core\strings\compile()},
 * so callers can pass conditional tokens without `array_filter()` boilerplate.
 *
 * @param string ...$keys One or more sort tokens (e.g. `'name'`, `'-created'`).
 *
 * @return string The comma-joined sort expression, or an empty string when no tokens are given.
 *
 * @example
 * ```php
 * use App\Enums\Prop;
 * use oihana\arango\enums\Arango;
 *
 * use function oihana\arango\helpers\descKey;
 * use function oihana\arango\helpers\sortKeys;
 *
 * sortKeys( descKey( Prop::CREATED ) )                                    ; // '-created'
 * sortKeys( descKey( Prop::CREATED ) , Prop::NAME )                       ; // '-created,name'
 * sortKeys( descKey( Prop::CREATED ) , descKey( Prop::NAME ) )            ; // '-created,-name'
 * sortKeys()                                                              ; // ''
 *
 * Arango::SORT => sortKeys( descKey( Prop::CREATED ) , Prop::_KEY ) ;
 * ```
 *
 * @see ascKey()  Ascending sort token.
 * @see descKey() Descending sort token.
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\helpers
 */
function sortKeys( string ...$keys ) :string
{
    return compile( $keys , Char::COMMA ) ;
}
