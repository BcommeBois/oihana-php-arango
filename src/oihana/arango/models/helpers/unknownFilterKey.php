<?php

namespace oihana\arango\models\helpers;

use oihana\arango\exceptions\RequestValidationException;
use oihana\enums\Char;

/**
 * Builds the refusal answered to a request whose filter names a key the model
 * does not declare at that level.
 *
 * A key absent from `AQL::FILTERS` used to be dropped : the predicate vanished, the
 * query left without it, and the whole collection came back in `200`. A caller that
 * trusted its `limit` then labelled its screen from whatever arrived first, with no
 * error, no empty page and nothing in its own log to notice. The refusal names the key
 * and, when the key is nested, the path it was looked up under — what the caller has
 * to correct, and nothing else : the keys the model does accept are documentation, not
 * an error message.
 *
 * The same sentence is answered at the root of a filter and at every depth of a
 * hierarchical one, so a mistyped key is told apart from nothing but its position.
 *
 * @param string        $key  The key as the request spelled it, without its `[*]` marker.
 * @param array<string> $path The parent segments the key was looked up under, empty at the root.
 *
 * @return RequestValidationException The `400` refusal, ready to be thrown.
 *
 * @example
 * ```php
 * throw unknownFilterKey( 'pays' ) ;             // The filter key "pays" is not filterable.
 * throw unknownFilterKey( 'pays' , [ 'address' ] ) ; // The filter key "pays" is not filterable at "address".
 * ```
 *
 * @package oihana\arango\models\helpers
 * @author  Marc Alcaraz (ekameleon)
 */
function unknownFilterKey( string $key , array $path = [] ) : RequestValidationException
{
    return new RequestValidationException( sprintf
    (
        'The filter key "%s" is not filterable%s.' ,
        $key ,
        $path === [] ? Char::EMPTY : sprintf( ' at "%s"' , implode( Char::DOT , $path ) )
    )) ;
}
