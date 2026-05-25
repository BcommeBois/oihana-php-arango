<?php

namespace oihana\arango\clients\helpers ;

/**
 * Merges the optional `new` / `old` payload returned by an ArangoDB
 * write endpoint into the meta document, so the caller sees a single
 * flat array regardless of whether `returnNew` / `returnOld` was set.
 *
 * On an insert / update / replace call with `returnNew: true`, the
 * server responds with:
 *
 * ```json
 * { "_key" : "..." , "_id" : "..." , "_rev" : "..." , "new" : { ... full document ... } }
 * ```
 *
 * The wire convention is that the meta attributes (`_key` / `_id` /
 * `_rev`, and for edges `_from` / `_to`) take precedence over the
 * payload — even when `_rev` is duplicated in both, the outer copy
 * is the authoritative one. This helper applies that precedence and
 * strips the now-redundant payload field from the result.
 *
 * When the payload field is absent (the caller did not request
 * `returnNew` / `returnOld`), `$body` is returned untouched.
 *
 * Pure function — used by Collection write paths and by the gharial
 * vertex/edge write paths.
 *
 * @param array<string, mixed> $body         Full response body carrying the meta attributes at the top level and (optionally) the payload under `$payloadField`.
 * @param string               $payloadField Name of the optional payload field (`new` on insert/update/replace, `old` on remove).
 *
 * @return array<string, mixed> The meta merged with the payload (meta wins on key collisions), with the payload field stripped. When the payload is absent, `$body` is returned unchanged.
 */
function mergeWrittenPayload( array $body , string $payloadField ) : array
{
    if ( !isset( $body[ $payloadField ] ) || !is_array( $body[ $payloadField ] ) )
    {
        return $body ;
    }

    $payload = $body[ $payloadField ] ;
    unset( $body[ $payloadField ] ) ;

    return array_merge( $payload , $body ) ;
}
