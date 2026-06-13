<?php

namespace oihana\arango\maskings;

use InvalidArgumentException;
use Random\RandomException;

/**
 * Applies a list of attribute masking rules to a single document.
 *
 * This is the portable counterpart of the `arangodump` masking engine. Each
 * rule is `{ "path": …, "type": <masker>, …params }`; the supported path forms
 * mirror the binary:
 *
 *  - `"name"`        — a leaf attribute `name` at the top level ;
 *  - `"a.b"`         — the exact nested path `a` → `b` (through objects only) ;
 *  - `".name"`       — every leaf attribute named `name`, at any depth ;
 *  - `"*"`           — every leaf attribute ;
 *  - `` "`a.b`" ``   — a literal attribute name containing dots (backtick/tick quoted).
 *
 * A **leaf** is a value that is `null`, a scalar or a JSON array; objects are
 * descended into. When a matched leaf is an array, the masker is applied to its
 * elements individually (see {@see maskValue()}). The top-level **system
 * attributes** `_key`, `_id`, `_rev`, `_from`, `_to` are never masked. When
 * several rules match the same leaf, the **first one** in the list wins.
 *
 * @param array $doc      The document (decoded JSON object).
 * @param array $maskings The list of rules for this collection.
 * @return array The masked document.
 *
 * @throws InvalidArgumentException When a rule has no `type`, or an unknown masker.
 * @throws RandomException
 *
 * @example
 * ```php
 * use function oihana\arango\maskings\maskDocument;
 *
 * $doc = [ '_key' => 'a' , 'email' => 'real@example.com' , 'profile' => [ 'name' => 'Jane' ] ] ;
 * maskDocument( $doc , [ [ 'path' => 'email' , 'type' => 'email' ] , [ 'path' => '.name' , 'type' => 'xifyFront' ] ] ) ;
 * // [ '_key' => 'a' , 'email' => 'aZ12.bY34@cX56.invalid' , 'profile' => [ 'name' => 'xxne' ] ]
 * ```
 *
 * @package oihana\arango\maskings
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function maskDocument( array $doc , array $maskings ) :array
{
    if( $maskings === [] )
    {
        return $doc ;
    }

    return maskDocumentNode( $doc , $maskings , '' , 0 ) ;
}

/**
 * The system attributes never masked (top level only).
 *
 * @internal
 * @return array<int,string>
 */
function maskingSystemAttributes() :array
{
    return [ '_key' , '_id' , '_rev' , '_from' , '_to' ] ;
}

/**
 * Returns the first rule (in declaration order) targeting the leaf identified by
 * its attribute name and its exact dotted path (the latter is `null` once an
 * array has been crossed, disabling exact-path matching).
 *
 * @internal
 * @param array       $maskings
 * @param string      $key
 * @param string|null $exactPath
 * @return array<string,mixed>|null
 */
function resolveMaskingRule( array $maskings , string $key , ?string $exactPath ) :?array
{
    foreach( $maskings as $rule )
    {
        $path = (string) ( $rule[ 'path' ] ?? '' ) ;

        if( $path === '*' )
        {
            return $rule ;
        }

        if( $path !== '' && ( $path[ 0 ] === '`' || $path[ 0 ] === '´' ) )
        {
            if( $exactPath === trim( $path , '`´' ) )
            {
                return $rule ;
            }
            continue ;
        }

        if( $path !== '' && $path[ 0 ] === '.' )
        {
            if( $key === substr( $path , 1 ) )
            {
                return $rule ;
            }
            continue ;
        }

        if( $exactPath !== null && $exactPath === $path )
        {
            return $rule ;
        }
    }

    return null ;
}

/**
 * Walks an object: masks the matching leaves and descends into nested objects
 * and arrays.
 *
 * @internal
 * @param array       $node
 * @param array       $maskings
 * @param string|null $exactPath
 * @param int         $depth
 * @return array
 * @throws InvalidArgumentException
 * @throws RandomException
 */
function maskDocumentNode( array $node , array $maskings , ?string $exactPath , int $depth ) :array
{
    $system = maskingSystemAttributes() ;
    $out    = [] ;

    foreach( $node as $key => $value )
    {
        $key = (string) $key ;

        if( $depth === 0 && in_array( $key , $system , true ) )
        {
            $out[ $key ] = $value ;
            continue ;
        }

        $childPath = $exactPath === null ? null : ( $exactPath === '' ? $key : $exactPath . '.' . $key ) ;

        if( is_array( $value ) && !array_is_list( $value ) )
        {
            $out[ $key ] = maskDocumentNode( $value , $maskings , $childPath , $depth + 1 ) ;
            continue ;
        }

        // Leaf (scalar, null or list array).
        $rule = resolveMaskingRule( $maskings , $key , $childPath ) ;
        if( $rule !== null )
        {
            if( !isset( $rule[ 'type' ] ) || !is_string( $rule[ 'type' ] ) )
            {
                throw new InvalidArgumentException( sprintf( "Masking rule for path '%s' has no type." , $rule[ 'path' ] ?? '?' ) ) ;
            }
            $out[ $key ] = maskValue( $rule[ 'type' ] , $value , $rule ) ;
        }
        elseif( is_array( $value ) )
        {
            $out[ $key ] = maskDocumentList( $value , $maskings , $depth + 1 ) ; // no rule on the array: look deeper
        }
        else
        {
            $out[ $key ] = $value ;
        }
    }

    return $out ;
}

/**
 * Walks a list: descends into element objects/arrays; bare scalars stay.
 *
 * @internal
 * @param array $list
 * @param array $maskings
 * @param int   $depth
 * @return array
 * @throws InvalidArgumentException
 * @throws RandomException
 */
function maskDocumentList( array $list , array $maskings , int $depth ) :array
{
    return array_map
    (
        static fn( $element ) => is_array( $element )
            ? ( array_is_list( $element ) ? maskDocumentList( $element , $maskings , $depth ) : maskDocumentNode( $element , $maskings , null , $depth ) )
            : $element ,
        $list ,
    ) ;
}
