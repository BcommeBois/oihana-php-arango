<?php

namespace oihana\arango\db\helpers;

use InvalidArgumentException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\EmptyObject;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\core\strings\compile;

/**
 * Generate a document expression for ArangoDB AQL.
 *
 * Accepts:
 * - associative arrays: ['key' => value, ...]
 * - indexed arrays of [key, value] pairs: [['key', value], ...]
 * - objects: converted to associative arrays
 * - strings: returned as-is inside braces
 * - null: returns '{}'
 *
 * Options can be passed as an associative array:
 * - 'useSpace'  : bool, add spaces around braces and after commas
 * - 'rawValues' : array, keys whose values should be treated as raw AQL expressions
 * - 'rawKeys'   : array, keys which should be kept raw (their values are not wrapped or converted)
 *
 * @param object|array|string|null $keyValues Array of key-value pairs, associative array, object, string, or null
 * @param array                    $options   Optional settings: ['useSpace'=>bool, 'rawValues'=>array, 'rawKeys'=>array]
 *
 * @return string JS-like object expression for AQL
 *
 * @throws InvalidArgumentException If array structure is invalid
 * @throws UnsupportedOperationException If a value type is unsupported.
 *
 * @example
 * Simple associative array
 * ```php
 * echo document([ '_from'=>'u._id', '_to'=>'p._id' ]);
 * // {_from:u._id,_to:p._id}
 * ```
 *
 * Add spaces around braces and commas
 * ```php
 * echo document([ '_from'=>'u._id', '_to'=>'p._id' ], ['useSpace'=>true]);
 * // { _from:u._id, _to:p._id }
 * ```
 *
 * Using raw values for AQL expressions
 * ```php
 * echo document([ '_key'=>"CONCAT('test', i)", 'name'=>"test", 'foobar'=>true ],
 * ['useSpace'=>true, 'rawValues'=>['_key']]);
 * // { _key: CONCAT('test', i), name:'test', foobar:true }
 * ```
 *
 * Forcing keys to be raw
 * ```php
 * echo document([ 'custom'=> 'something' ], ['rawKeys'=>['custom']]);
 * // {custom:something}
 * ```
 *
 * Nested associative array
 * ```php
 * echo document(['user'=>['name'=>'Eka','age'=>47],'active'=>true]);
 * // {user:{name:'Eka',age:47},active:true}
 * ```
 *
 * Indexed arrays (lists)
 * ```php
 * echo document(['tags'=>['php','js'],'scores'=>[10,20,30]]);
 * // {tags:['php','js'],scores:[10,20,30]}
 * ```
 *
 * Object input
 * ```php
 * $obj = (object)['name'=>'Eka','age'=>47];
 * echo document($obj);
 * // {name:'Eka',age:47}
 * ```
 *
 * Preformatted string
 * ```php
 * echo document("foo:'bar'");
 * // {foo:'bar'}
 * ```
 *
 * Null or empty
 * ```php
 * echo document(null);
 * // {}
 * echo document([], ['useSpace'=>true]);
 * // { }
 * ```
 *
 * @package oihana\arango\db\helpers
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
function aqlDocument
(
    object|array|string|null $keyValues = [] ,
    array $options                      = []
): string
{
    $useSpace = $options[ AQL::USE_SPACE ] ?? false ;

    $space = $useSpace ? Char::SPACE : Char::EMPTY ;

    if ( is_null( $keyValues ) || ( is_array( $keyValues ) && empty( $keyValues ) ) )
    {
        return $useSpace ? EmptyObject::SPACED : EmptyObject::COMPACT ;
    }

    if ( is_object( $keyValues ) )
    {
        $keyValues = get_object_vars( $keyValues ) ;
    }

    if ( is_string( $keyValues ) )
    {
        return Char::LEFT_BRACE . $space . trim( $keyValues ) . $space . Char::RIGHT_BRACE ;
    }

    $escapeKey = static fn( string $key ) :string
               => preg_match( '/^[a-zA-Z_]\w*$/' , $key ) ? $key : Char::APOSTROPHE . addslashes( $key ) . Char::APOSTROPHE ;

    $rawKeys   = is_array($options[ AQL::RAW_KEYS   ] ?? null ) ? $options[ AQL::RAW_KEYS   ] : [] ;
    $rawValues = is_array($options[ AQL::RAW_VALUES ] ?? null ) ? $options[ AQL::RAW_VALUES ] : [] ;
    $aqlify    = fn( $value , ?string $key = null ):string => in_array( $key , $rawKeys , true ) ? $value : aqlValue( $value, $rawValues ) ;

    $parts = [] ;
    foreach ( $keyValues as $key => $value )
    {
        if ( is_string( $key ) )
        {
            $parts[] = $escapeKey( $key ) . Char::COLON . $aqlify( $value , $key ) ;
        }
        elseif ( is_array( $value ) && count( $value ) === 2 )
        {
            $parts[] = $escapeKey( (string) $value[0] ) . Char::COLON . $aqlify( $value[1] , (string) $value[0] ) ;
        }
        elseif ( is_object( $value ) )
        {
            $parts[] = aqlDocument( $value, $options );
        }
        elseif ( is_string( $value ) )
        {
            $parts[] = trim( $value ) ;
        }
        else
        {
            throw new InvalidArgumentException( "Invalid array item: must be [key,value], associative, object, or string" ) ;
        }
    }

    return Char::LEFT_BRACE . $space . compile( $parts , Char::COMMA . $space ) . $space . Char::RIGHT_BRACE ;
}