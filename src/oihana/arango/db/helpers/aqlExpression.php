<?php

namespace oihana\arango\db\helpers;

use oihana\exceptions\UnsupportedOperationException;

/**
 * Converts a value into an AQL expression.
 *
 * Accepts either:
 * - A **string** representing a raw AQL expression, returned as-is.
 * - An **array** or **object** representing key-value pairs, converted into
 *   an AQL document object using {@see aqlDocument()}.
 * - `null`, which results in a `null` return value.
 *
 * Internally, this function delegates to {@see aqlDocument()} when `$value`
 * is an array or object, and otherwise returns the raw string.
 *
 * ### Examples:
 *
 * ```php
 * echo aqlExpression( "FOR u IN users RETURN u" )               ; // "FOR u IN users RETURN u"
 * echo aqlExpression( ['name' => 'John', 'age' => 30] )        ; // "{name:'John',age:30}"
 * echo aqlExpression( [['status', 'active']] )                 ; // "{status:'active'}"
 * echo aqlExpression( null )                                   ; // null
 * ```
 *
 * @param object|string|array|null $value The value to convert into an AQL expression.
 *
 * @return string|null Returns the raw AQL expression or a converted document string,
 *                     or `null` if the input value is `null`.
 *
 * @throws UnsupportedOperationException If a value type is unsupported.
 *
 * @author  Marc Alcaraz
 * @since   1.0.0
 * @package oihana\arango\db\helpers
 */
function aqlExpression( object|string|array|null $value ): ?string
{
    if( is_null( $value ) )
    {
        return null ;
    }

    if( is_string( $value ) )
    {
        return $value ;
    }

    return aqlDocument( $value );
}