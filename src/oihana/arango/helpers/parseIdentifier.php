<?php

namespace oihana\arango\helpers ;

use oihana\arango\enums\Arango;

/**
 * Parses an ArangoDB document handle (`<collection>/<key>`) into its
 * components.
 *
 * PHP mirror of the AQL function
 * [`PARSE_IDENTIFIER`](https://docs.arango.ai/arangodb/stable/aql/functions/document-object/#parse_identifier).
 * Useful when working with `_id`, `_from` or `_to` strings in PHP land
 * — e.g. when iterating through edge documents returned by a model
 * `list()` and only the `_key` or the `collection` name is needed.
 *
 * @param string|null $id The full document handle (e.g. `"users/abc123"`).
 *
 * @return array{collection: string|null, key: string|null}|null
 *         An associative array with `collection` and `key` keys, or
 *         `null` if `$id` is null / empty.
 *
 * @example
 * ```php
 * parseIdentifier( 'users/abc123' ) ;
 * // [ 'collection' => 'users' , 'key' => 'abc123' ]
 *
 * parseIdentifier( 'just-a-key' ) ;
 * // [ 'collection' => null , 'key' => 'just-a-key' ]
 *
 * parseIdentifier( null ) ; // null
 * parseIdentifier( '' )   ; // null
 * ```
 *
 * @see parseKey()        Returns just the `_key` portion.
 * @see parseCollection() Returns just the collection name.
 * @see https://docs.arango.ai/arangodb/stable/aql/functions/document-object/#parse_identifier
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\helpers
 */
function parseIdentifier( ?string $id ) :?array
{
    if ( $id === null || $id === '' )
    {
        return null ;
    }

    $slashAt = strpos( $id , '/' ) ;

    if ( $slashAt === false )
    {
        return [ Arango::COLLECTION => null , Arango::KEY => $id ] ;
    }

    return
    [
        Arango::COLLECTION => substr( $id , 0 , $slashAt ) ,
        Arango::KEY        => substr( $id , $slashAt + 1 ) ,
    ] ;
}
