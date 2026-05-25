<?php

namespace oihana\arango\clients\helpers ;

/**
 * Extracts a single wrapper field from a server response body, with
 * a typed fallback when the field is missing or the body itself is
 * not an array.
 *
 * ArangoDB consistently wraps payloads inside an envelope on its
 * `/_api/gharial/*` and `/_api/transaction/*` endpoints:
 *
 * ```json
 * { "graph"  : { ... } }      // GET /_api/gharial/{name}
 * { "vertex" : { ... } }      // GET /_api/gharial/{g}/vertex/{c}/{k}
 * { "edge"   : { ... } }      // GET /_api/gharial/{g}/edge/{c}/{k}
 * { "result" : { ... } }      // GET /_api/transaction/{id}
 * ```
 *
 * `unwrapField()` reads that wrapper and returns the inner payload
 * (or `$fallback` when the wrapper is absent / malformed). Pure
 * function — no side effects, defensive against partial responses.
 *
 * @param mixed  $body     Decoded response body.
 * @param string $field    Name of the wrapper field to extract.
 * @param mixed  $fallback Value to return when `$body` is not an array, or when the field is missing or not an array itself.
 *
 * @return mixed The unwrapped payload, or `$fallback`.
 */
function unwrapField( mixed $body , string $field , mixed $fallback = null ) : mixed
{
    if ( !is_array( $body ) )
    {
        return $fallback ;
    }

    $value = $body[ $field ] ?? null ;

    return is_array( $value ) ? $value : $fallback ;
}
