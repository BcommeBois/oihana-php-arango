<?php

namespace oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use oihana\enums\Char;

/**
 * Whether a dotted path (`seller.id`) is declared under a level of `AQL::FILTERS`.
 *
 * A sub-field of an object is declared in one of two ways, and both are read :
 *
 * - **flat**, the whole dotted path as one key of the level :
 *   `[ 'seller.id' => FilterType::STRING ]` ;
 * - **as an object**, each segment declared inside the object of the previous one :
 *   `[ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'id' => FilterType::STRING ] ] ]`.
 *
 * 🚨 **One rule for both spellings of a filter on an element of a list.** The direct
 * one (`offers[*].seller.id`) walked the path segment by segment and read only the
 * object form ; the `match` one (`offers[*]` with `{"seller.id":…}`) looked the dotted
 * key up as is and read only the flat form. No declaration served both, and whichever
 * a model chose, one of the two answered `400` to a key it did declare.
 *
 * An object declared with no sub-filters is free below it, as a list declared with
 * none is. A path neither form declares is not.
 *
 * @param array<array-key,mixed> $declared The `AQL::FILTERS` of the level the path starts at.
 * @param string                 $path     The dotted path, without any `[*]` marker.
 *
 * @return bool
 *
 * @example
 * ```php
 * isDeclaredFilterPath( [ 'seller.id' => FilterType::STRING ] , 'seller.id' ) ; // true
 * isDeclaredFilterPath( [ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'id' => FilterType::STRING ] ] ] , 'seller.id' ) ; // true
 * isDeclaredFilterPath( [ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'id' => FilterType::STRING ] ] ] , 'seller.name' ) ; // false
 * ```
 *
 * @package oihana\arango\models\helpers
 * @author  Marc Alcaraz (ekameleon)
 */
function isDeclaredFilterPath( array $declared , string $path ) : bool
{
    if ( array_key_exists( $path , $declared ) )
    {
        return true ;
    }

    $dot = strpos( $path , Char::DOT ) ;

    if ( $dot === false )
    {
        return false ;
    }

    $definition = $declared[ substr( $path , 0 , $dot ) ] ?? null ;
    $below      = is_array( $definition ) ? ( $definition[ AQL::FILTERS ] ?? null ) : null ;

    if ( !is_array( $below ) )
    {
        return false ;
    }

    return $below === [] || isDeclaredFilterPath( $below , substr( $path , $dot + 1 ) ) ;
}
