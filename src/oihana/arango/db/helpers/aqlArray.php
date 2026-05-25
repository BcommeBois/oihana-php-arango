<?php

namespace oihana\arango\db\helpers;

use oihana\enums\Char;

/**
 * Convert a value to an AQL array expression.
 *
 * This helper converts various PHP values to their AQL array representation.
 * Objects are cast to arrays, arrays are JSON-encoded, strings are returned
 * as-is, and other values return an empty array expression.
 *
 * @param mixed $value Array, object, or string to convert.
 * @return string AQL array expression.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\aqlArray;
 *
 * aqlArray([1, 2, 3]);           // '[1,2,3]'
 * aqlArray('doc.items');         // 'doc.items' (string returned as-is)
 * aqlArray(new stdClass());      // '[]' (object cast to empty array)
 * aqlArray(123);                 // '[]' (non-array/non-string returns empty array)
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlArray( mixed $value = null ) : string
{
    if( is_object( $value ) )
    {
        $value = (array) $value ;
    }

    if( is_array( $value ) )
    {
        return json_encode( $value ) ;
    }

    if( is_string( $value ) )
    {
        return $value ; // do nothing
    }

    return Char::LEFT_BRACKET . Char::RIGHT_BRACKET ; // -> empty []
}
