<?php

namespace oihana\arango\helpers ;

/**
 * Returns the distinct `_key`s of one end of a list of edges (`_from` or `_to`).
 *
 * Each edge is read by {@see edgeEndKey()} ; an edge without that end is
 * skipped. The keys keep their first order and stay strings, even when
 * they look like numbers.
 *
 * @param iterable<mixed> $edges The edges, as objects or arrays.
 * @param string          $end   The end to read : `Schema::_FROM` or `Schema::_TO`.
 *
 * @return array<int, string> The distinct keys.
 *
 * @example
 * ```php
 * use org\schema\constants\Schema ;
 *
 * edgeEndKeys
 * ([
 *     [ '_from' => 'roles/42' , '_to' => 'policies/p1' ] ,
 *     [ '_from' => 'roles/42' , '_to' => 'policies/p2' ] ,
 *     [ '_from' => 'roles/7'  , '_to' => 'policies/p1' ] ,
 * ] , Schema::_FROM ) ; // [ '42' , '7' ]
 * ```
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\helpers
 */
function edgeEndKeys( iterable $edges , string $end ) :array
{
    $keys = [] ;
    $seen = [] ;

    foreach( $edges as $edge )
    {
        $key = edgeEndKey( $edge , $end ) ;

        if( $key !== null && !isset( $seen[ $key ] ) )
        {
            $seen[ $key ] = true ;
            $keys[]       = $key ;
        }
    }

    return $keys ;
}
