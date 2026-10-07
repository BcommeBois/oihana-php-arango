<?php

namespace oihana\arango\models\helpers;

use oihana\arango\models\enums\filters\FilterParam;

use function oihana\core\arrays\isAssociative;

/**
 * Tells whether an array is a filter — a segment or a logical group — rather than a
 * model init that merely carries no filter.
 *
 * `prepareFilter()` receives both : the whole `$init` of a `list()` call, where
 * `Arango::FILTER` may be absent, and the filter payload itself, at the root or as a
 * child of an `and` / `or` group. An init without a filter is no fault — it is the
 * ordinary unfiltered list. A segment that says `val` or `op` but names no `key` is
 * one, and so is any filter on a model that declares no filterable key at all. The two
 * read alike (an associative array without `key`), and this is what tells them apart :
 * a filter is a list (a logical group), or it names a key, or it speaks the filter
 * grammar — a value, an operator, a match, a quantifier, a function, a bound.
 *
 * @param array<array-key,mixed> $value The array to qualify.
 *
 * @return bool `true` for a filter segment or group, `false` for anything else.
 *
 * @example
 * ```php
 * isFilterSegment( [ 'key' => 'name' , 'val' => 'x' ] ) ; // true
 * isFilterSegment( [ 'val' => 'x' ] ) ;                   // true  — a segment that names no key
 * isFilterSegment( [ 'and' , [ … ] , [ … ] ] ) ;          // true  — a logical group
 * isFilterSegment( [ 'limit' => 10 ] ) ;                  // false — an init without a filter
 * ```
 *
 * @package oihana\arango\models\helpers
 * @author  Marc Alcaraz (ekameleon)
 */
function isFilterSegment( array $value ) : bool
{
    if ( !isAssociative( $value ) )
    {
        return true ;
    }

    foreach ( [ FilterParam::KEY , FilterParam::VAL , FilterParam::OP , FilterParam::MATCH , FilterParam::QUANT , FilterParam::ALT , FilterParam::MIN , FilterParam::MAX ] as $word )
    {
        if ( array_key_exists( $word , $value ) )
        {
            return true ;
        }
    }

    return false ;
}
