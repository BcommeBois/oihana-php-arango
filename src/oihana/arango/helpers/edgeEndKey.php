<?php

namespace oihana\arango\helpers ;

/**
 * Returns the `_key` of one end of an edge (`_from` or `_to`).
 *
 * Accepts the edge as an object or as an associative array, as the edge
 * models return it. The end is read as a document handle
 * (`<collection>/<key>`) and reduced to its `_key` by {@see parseKey()}.
 *
 * @param mixed  $edge The edge : an object, an array, or anything else (answers `null`).
 * @param string $end  The end to read : `Schema::_FROM` or `Schema::_TO`.
 *
 * @return string|null The `_key` of that end, or `null` when the edge has no such end, or a non-string one.
 *
 * @example
 * ```php
 * use org\schema\constants\Schema ;
 *
 * edgeEndKey( [ '_from' => 'roles/42' , '_to' => 'permissions/7' ] , Schema::_FROM ) ; // '42'
 * edgeEndKey( (object) [ '_to' => 'policies/p1' ] , Schema::_TO )                    ; // 'p1'
 * edgeEndKey( (object) [] , Schema::_FROM )                                          ; // null
 * ```
 *
 * @see edgeEndKeys() The distinct keys of one end of a list of edges.
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\helpers
 */
function edgeEndKey( mixed $edge , string $end ) :?string
{
    $id = match( true )
    {
        is_object( $edge ) => $edge->{ $end } ?? null ,
        is_array ( $edge ) => $edge[ $end ]   ?? null ,
        default            => null ,
    } ;

    return is_string( $id ) ? parseKey( $id ) : null ;
}
