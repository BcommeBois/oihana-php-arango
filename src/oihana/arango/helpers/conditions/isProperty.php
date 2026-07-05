<?php

namespace oihana\arango\helpers\conditions ;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;

use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlValue;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\in;
use function oihana\core\strings\key ;

/**
 * Build an AQL equality condition on a document property.
 *
 * This helper creates an equality condition (`==`) when a scalar value or
 * AQL expression is provided, and an `IN` condition when an array of values
 * is given.
 *
 *
 * @param string            $property The property name (Prop::*)
 * @param null|string|array $value    The expected value or expression.
 * @param string            $docRef   The AQL doc reference (default: doc)
 *
 * @return array An array containing a single AQL condition expression
 *
 * @example
 * ```php
 * isProperty('status', 'active');
 * // → doc.status == 'active'
 *
 * isProperty('status', ['active', 'pending']);
 * // → doc.status IN ['active','pending']
 *
 * isProperty('tags', '@tags');
 * // → doc.tags == @tags
 *
 * isProperty('name', 'doc2.name');
 * // → doc.name == doc2.name
 *
 * isProperty('table', [1, 'hello', true, '\@_param']);
 * // → doc.table IN [1,'hello',true,@param]
 * ```
 *
 * @throws InvalidArgumentException      If the value is null, empty, or invalid
 * @throws UnsupportedOperationException If the value type is unsupported.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.5.0
 */
function isProperty
(
    string            $property,
    null|string|array $value ,
    string            $docRef = AQL::DOC
)
:array
{
    if ( $value === null )
    {
        throw new InvalidArgumentException( 'isProperty(): value cannot be null' ) ;
    }

    if ( $value === [] )
    {
        throw new InvalidArgumentException( 'isProperty(): value array cannot be empty' ) ;
    }

    if ( is_string( $value ) && trim( $value ) === '' )
    {
        throw new InvalidArgumentException( 'isProperty(): value string cannot be empty' ) ;
    }

    $key = key( $property , $docRef ) ;
    return
    [
        is_array( $value ) ? in( $key , aqlValue( $value ) ) : equal( $key , aqlValue( $value ) )
    ];
}