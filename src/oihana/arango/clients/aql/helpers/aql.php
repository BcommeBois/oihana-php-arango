<?php

namespace oihana\arango\clients\aql\helpers ;

use oihana\arango\clients\aql\AqlLiteral ;
use oihana\arango\clients\aql\AqlQuery ;

/**
 * Builds an {@see AqlQuery} from a string template and a sequence of values.
 *
 * Each `?` placeholder in `$template` is consumed in left-to-right order
 * and substituted with a fresh bind reference (`@value1`, `@value2`, …).
 * The corresponding values are stored in the resulting `bindVars` map so
 * they are serialised safely by the server-side bind resolver — there is
 * no way to provoke AQL injection through this layer.
 *
 * Two value types receive special treatment:
 * - {@see AqlLiteral} — inlined verbatim into the query (no bind). Use this
 *   for AQL keywords / function names that cannot be parameterised. Build
 *   one with {@see aqlLiteral()}.
 * - Anything else (scalar, array, null) — bound as a value parameter.
 *
 * When the caller wants to bind a collection (using the `@@name`
 * double-`@` syntax), the resulting `AqlQuery` should be assembled
 * manually (or via the existing query-builder helpers) rather than
 * through this helper, which only emits single-`@` value binds.
 *
 * Example:
 * ```php
 * $minAge    = 18 ;
 * $direction = aqlLiteral( 'DESC' ) ; // safe to inline because whitelisted
 *
 * $query = aql
 * (
 *     'FOR u IN users FILTER u.age > ? SORT u.name ? RETURN u' ,
 *     $minAge ,
 *     $direction ,
 * ) ;
 *
 * // $query->query    === 'FOR u IN users FILTER u.age > @value1 SORT u.name DESC RETURN u'
 * // $query->bindVars === [ 'value1' => 18 ]
 * ```
 *
 * @param string $template  Template string with `?` placeholders.
 * @param mixed  ...$values Values to substitute, in the order they appear in `$template`.
 *
 * @return AqlQuery
 */
function aql( string $template , mixed ...$values ) : AqlQuery
{
    $bindVars = [] ;
    $cursor   = 0 ;
    $index    = 0 ;

    $query = preg_replace_callback
    (
        '/\?/' ,
        static function() use ( &$cursor , &$index , &$bindVars , $values ) : string
        {
            $value = $values[ $cursor++ ] ?? null ;

            if ( $value instanceof AqlLiteral )
            {
                return $value->value ;
            }

            $bindName              = 'value' . ( ++$index ) ;
            $bindVars[ $bindName ] = $value ;

            return '@' . $bindName ;
        } ,
        $template ,
    ) ;

    return new AqlQuery( $query ?? $template , $bindVars ) ;
}
