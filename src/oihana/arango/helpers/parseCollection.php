<?php

namespace oihana\arango\helpers ;

/**
 * Returns the collection name portion of an ArangoDB document handle
 * (`<collection>/<key>`).
 *
 * PHP mirror of the AQL function
 * [`PARSE_COLLECTION`](https://docs.arango.ai/arangodb/stable/aql/functions/document-object/#parse_collection).
 * Returns `null` when the input has no `/` separator (a bare `_key` is
 * not a valid handle and has no collection to extract).
 *
 * @param string|null $id The full document handle (e.g. `"users/abc123"`).
 *
 * @return string|null The collection name, or `null` when `$id` is
 *                     null / empty / has no `/`.
 *
 * @example
 * ```php
 * parseCollection( 'users/abc123' ) ; // 'users'
 * parseCollection( 'roles/42' )     ; // 'roles'
 * parseCollection( 'just-a-key' )   ; // null (no separator)
 * parseCollection( null )           ; // null
 * parseCollection( '' )             ; // null
 * ```
 *
 * @see parseIdentifier() Returns the full `{collection, key}` pair.
 * @see parseKey()        Returns just the `_key` portion.
 * @see https://docs.arango.ai/arangodb/stable/aql/functions/document-object/#parse_collection
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\helpers
 */
function parseCollection( ?string $id ) :?string
{
    if ( $id === null || $id === '' )
    {
        return null ;
    }

    $slashAt = strpos( $id , '/' ) ;

    if ( $slashAt === false )
    {
        return null ;
    }

    return substr( $id , 0 , $slashAt ) ;
}
