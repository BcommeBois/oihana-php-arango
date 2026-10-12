<?php

namespace oihana\arango\models\helpers;

use oihana\arango\exceptions\RequestValidationException;
use oihana\enums\Char;

/**
 * Builds the refusal answered to a request whose filter has a shape no filter reads : not
 * a segment `{"key":…}`, not a group `["and"|"or"|"not", …]`.
 *
 * Such a filter used to be dropped : no clause, no error, and the whole collection came
 * back in `200` — a Mongo-style `{"age":{"$gte":30}}`, a mistyped `{"agee":1}`, a filter
 * left as a JSON string, the `{"logic":…,"conditions":[…]}` form the documentation once
 * taught. Inside an `and`, the child vanished and loosened the group. The refusal names
 * what arrived — the type of a scalar, the keys of an object — and the two shapes that
 * would have been read.
 *
 * @param mixed $filter The filter, or the member of a group, as the request sent it.
 *
 * @return RequestValidationException The `400` refusal, ready to be thrown.
 *
 * @example
 * ```php
 * throw unreadableFilter( '{"key":"age"}' ) ;
 * // A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : string given.
 *
 * throw unreadableFilter( [ 'agee' => 1 ] ) ;
 * // The filter {"agee"} speaks no filter grammar : name the field with "key" — {"key":…,"op":…,"val":…} — or write a group as a list — ["and"|"or"|"not", …].
 *
 * throw unreadableFilter( [] ) ;
 * // A filter group holds no condition.
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models\helpers
 * @version 1.0.0
 */
function unreadableFilter( mixed $filter ) :RequestValidationException
{
    if ( !is_array( $filter ) )
    {
        return new RequestValidationException( sprintf
        (
            'A filter is a segment {"key":…} or a group ["and"|"or"|"not", …] : %s given.' ,
            get_debug_type( $filter )
        )) ;
    }

    if ( $filter === [] )
    {
        return new RequestValidationException( 'A filter group holds no condition.' ) ;
    }

    // The keys the request sent — the few first : enough to recognise the mistake.
    $keys = array_map
    (
        static fn( int|string $key ) :string => Char::DOUBLE_QUOTE . $key . Char::DOUBLE_QUOTE ,
        array_slice( array_keys( $filter ) , 0 , 5 )
    ) ;

    return new RequestValidationException( sprintf
    (
        'The filter {%s%s} speaks no filter grammar : name the field with "key" — {"key":…,"op":…,"val":…} — or write a group as a list — ["and"|"or"|"not", …].' ,
        implode( Char::COMMA , $keys ) ,
        count( $filter ) > 5 ? Char::COMMA . '…' : Char::EMPTY
    )) ;
}
