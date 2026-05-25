<?php

namespace oihana\arango\db\helpers;

use JsonSerializable;
use function oihana\core\arrays\isAssociative;

/**
 * Serialize a value (array, object, scalar) into an AQL fragment.
 *
 * CustomRules:
 * - Strings are returned as-is (raw expression)
 * - Associative arrays => {key:value}
 * - Indexed arrays => [v1,v2,...]
 * - Objects => {key:value} using public properties
 * - JsonSerializable objects are jsonSerialized first
 *
 * @param mixed $value
 * @param bool $topLevel
 * @return string
 *
 * @example Associative array
 * ```php
 * echo aqlSerialize(['name' => 'John', 'age' => 30]);
 * // {name:'John',age:30}
 * ```
 *
 * @example Indexed array
 * ```php
 * echo aqlSerialize([1, 2, 3]);
 * // [1,2,3]
 * ```
 *
 * @example Nested array
 * ```php
 * echo aqlSerialize(['user' => ['id' => 1, 'tags' => ['php','js']]]);
 * // {user:{id:1,tags:['php','js']}}
 * ```
 *
 * @example Object
 * ```php
 * $obj = (object)['name' => 'Eka', 'age' => 47];
 * echo aqlSerialize($obj);
 * // {name:'Eka',age:47}
 * ```
 *
 * @example Nested object
 * ```php
 * $obj = (object)['user' => (object)['name'=>'Eka','age'=>47]];
 * echo aqlSerialize($obj);
 * // {user:{name:'Eka',age:47}}
 * ```
 *
 * @example JsonSerializable object
 * ```php
 * class User implements JsonSerializable {
 * public string $name = 'John';
 * public function jsonSerialize() { return ['name'=>$this->name]; }
 * }
 * $user = new User();
 * echo aqlSerialize($user);
 * // {name:'John'}
 * ```
 *
 * @example String (raw AQL)
 * ```php
 * echo aqlSerialize("FOR u IN users RETURN u");
 * // FOR u IN users RETURN u
 * ```
 *
 * @example Scalar values
 * ```php
 * echo aqlSerialize(true);
 * // true
 * echo aqlSerialize(123);
 * // 123
 * ```
 *
 * @example Mixed nested structures
 * ```php
 * $data =
 * [
 *    'user' => (object)['id'=>1,'roles'=>['admin','editor']],
 *    'tags' => ['php','js'],
 *    'count' => 10
 * ];
 * echo aqlSerialize($data);
 * // {user:{id:1,roles:['admin','editor']},tags:['php','js'],count:10}
 * ```
 *
 * @author  Marc Alcaraz
 * @since   1.0.0
 * @package oihana\arango\db\helpers
 */
function aqlSerialize( mixed $value , bool $topLevel = true ): string
{
    if ( $value instanceof JsonSerializable )
    {
        $value = $value->jsonSerialize() ;
    }

    if ( is_array( $value ) )
    {
        if ( isAssociative( $value ) )
        {
            $parts = [];
            foreach ($value as $k => $v)
            {
                $parts[] = $k . ':' . aqlSerialize( $v , false );
            }
            return '{' . implode(',', $parts) . '}' ;
        }
        else
        {
            $parts = array_map(fn($v) => aqlSerialize( $v , false ), $value);
            return '[' . implode(',', $parts) . ']' ;
        }
    }

    if ( is_object( $value ) )
    {
        $props = get_object_vars( $value );
        $parts = [] ;
        foreach ( $props as $k => $v )
        {
            $parts[] = $k . ':' . aqlSerialize( $v , false ) ;
        }
        return '{' . implode(',', $parts) . '}' ;
    }

    if ( is_string( $value ) )
    {
        return $topLevel ? $value : "'" . addslashes($value) . "'";
    }

    return var_export( $value , true ) ;
}