<?php

namespace oihana\arango\helpers ;

/**
 * Returns the `_key` portion of an ArangoDB document handle
 * (`<collection>/<key>`).
 *
 * PHP mirror of the AQL function
 * [`PARSE_KEY`](https://docs.arango.ai/arangodb/stable/aql/functions/document-object/#parse_key).
 * If the input has no `/` separator it is returned as-is — the helper
 * accepts both full handles (`"users/abc123"`) and bare keys
 * (`"abc123"`) and always returns a usable `_key` string.
 *
 * @param string|null $id The full document handle (e.g. `"users/abc123"`)
 *                        or a bare `_key`.
 *
 * @return string|null The `_key` portion, or `null` when `$id` is null
 *                     / empty.
 *
 * @example
 * ```php
 * parseKey( 'users/abc123' ) ; // 'abc123'
 * parseKey( 'roles/42' )     ; // '42'
 * parseKey( 'just-a-key' )   ; // 'just-a-key' (passes through)
 * parseKey( null )           ; // null
 * parseKey( '' )             ; // null
 * ```
 *
 * @see parseIdentifier() Returns the full `{collection, key}` pair.
 * @see parseCollection() Returns just the collection name.
 * @see https://docs.arango.ai/arangodb/stable/aql/functions/document-object/#parse_key
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\helpers
 */
function parseKey( ?string $id ) :?string
{
    if ( $id === null || $id === '' )
    {
        return null ;
    }

    $slashAt = strpos( $id , '/' ) ;

    if ( $slashAt === false )
    {
        return $id ;
    }

    return substr( $id , $slashAt + 1 ) ;
}
