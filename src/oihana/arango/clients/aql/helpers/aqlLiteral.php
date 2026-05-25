<?php

namespace oihana\arango\clients\aql\helpers ;

use oihana\arango\clients\aql\AqlLiteral ;

/**
 * Convenience helper for {@see AqlLiteral} construction.
 *
 * Wraps a raw AQL fragment so that {@see aql()} interpolates it verbatim
 * into the resulting query string instead of binding it as a parameter.
 * The fragment MUST come from a trusted source (validated whitelist,
 * server-side enum, …) — never from raw user input.
 *
 * Example:
 * ```php
 * $cursor = $db->query
 * (
 *     aql( 'FOR u IN users SORT u.name ? RETURN u' , aqlLiteral( 'DESC' ) )
 * ) ;
 * // → "FOR u IN users SORT u.name DESC RETURN u"
 * ```
 *
 * @param string $value Raw AQL fragment to inline.
 *
 * @return AqlLiteral
 */
function aqlLiteral( string $value ) : AqlLiteral
{
    return new AqlLiteral( $value ) ;
}
