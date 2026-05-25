<?php

namespace oihana\arango\clients\helpers ;

use oihana\enums\Boolean ;

/**
 * Coerces boolean entries of a server-options array to the lowercase
 * `"true"` / `"false"` spelling ArangoDB expects when these options
 * travel on the query string of a request.
 *
 * Non-boolean values are forwarded as-is. Integer, float and string
 * options keep their wire shape; only the booleans are normalised,
 * because Guzzle would otherwise serialise PHP `true` / `false` as
 * `"1"` / `""` — which ArangoDB rejects.
 *
 * Pure function (no state, no side effects). Used by every
 * surface of the client that forwards a per-call options array as
 * query parameters:
 * - {@see \oihana\arango\clients\collection\Collection},
 * - {@see \oihana\arango\clients\graph\GraphVertexCollection},
 * - {@see \oihana\arango\clients\graph\GraphEdgeCollection},
 * - and the bulk import / batch flows.
 *
 * @param array<string, mixed> $options
 *
 * @return array<string, mixed>
 */
function stringifyOptions( array $options ) : array
{
    foreach ( $options as $key => $value )
    {
        if ( is_bool( $value ) )
        {
            $options[ $key ] = $value ? Boolean::TRUE : Boolean::FALSE ;
        }
    }

    return $options ;
}
