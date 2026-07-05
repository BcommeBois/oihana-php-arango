<?php

namespace oihana\arango\helpers\conditions ;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;

use org\schema\Thing;

/**
 * Filter documents by the canonical schema type of a Thing class.
 *
 * Resolves the absolute type URI of the given class — its CONTEXT plus its
 * short name, see Thing::getSchemaType() — and delegates to
 * isAdditionalType(). Accepts several classes for an IN condition.
 *
 * @param class-string<Thing>|array<class-string<Thing>> $class  The Thing class(es) to filter by.
 * @param string                                         $docRef The AQL doc reference (default: doc).
 *
 * @return array An array containing a single AQL condition expression.
 *
 * @throws InvalidArgumentException      If a class is not Thing or a Thing subclass.
 * @throws UnsupportedOperationException If the resolved value type is unsupported.
 *
 * @example
 * ```php
 * use xyz\oihana\schema\organizations\Customer;
 * use xyz\oihana\schema\places\CustomerSite;
 * use xyz\oihana\schema\places\Warehouse;
 *
 * isSchemaType( Customer::class ) ;
 * // → doc.additionalType == 'https://schema.oihana.xyz/Customer'
 *
 * isSchemaType([ CustomerSite::class , Warehouse::class ]) ;
 * // → doc.additionalType IN [ '…/CustomerSite' , '…/Warehouse' ]
 * ```
 */
function isSchemaType( string|array $class , string $docRef = AQL::DOC ) : array
{
    $classes = is_array( $class ) ? $class : [ $class ] ;

    $types = array_map( static function( string $class ) :string
    {
        if ( !is_a( $class , Thing::class , true ) )
        {
            throw new InvalidArgumentException( sprintf( 'isSchemaType(): "%s" is not %s or a subclass of it.' , $class , Thing::class ) ) ;
        }
        return $class::getSchemaType() ;
    } , $classes ) ;

    return isAdditionalType( count( $types ) === 1 ? $types[0] : $types , $docRef ) ;
}