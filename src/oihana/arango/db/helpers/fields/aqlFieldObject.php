<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\functions\isObject;
use function oihana\arango\db\functions\notNull;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\keyValue;

/**
 * Projects a field as a single object : the value itself when it is one, its
 * first element when it is an array, `null` otherwise.
 *
 * ```php
 * aqlFieldObject( 'author' , 'doc.author' ) ;
 * // author:IS_OBJECT(doc.author) ? doc.author : IS_ARRAY(doc.author) ? FIRST(doc.author) : null
 * ```
 *
 * With `$else`, the expression a resolution that gave nothing falls back on —
 * the stored reference of a join, typically, so a record naming a document the
 * target collection does not hold keeps the code it stored instead of losing the key :
 *
 * ```php
 * aqlFieldObject( 'about' , 'about_j1' , 'doc.about' ) ;
 * // about:NOT_NULL(IS_OBJECT(about_j1) ? about_j1 : IS_ARRAY(about_j1) ? FIRST(about_j1) : null,doc.about)
 * ```
 *
 * @param string      $key   The projected key.
 * @param string      $value The AQL reference of the value — a document path or a `LET` variable.
 * @param string|null $else  The AQL expression to fall back on when the value resolves to `null`.
 *
 * @return string The `key:expression` fragment.
 */
function aqlFieldObject( string $key , string $value , ?string $else = null ): string
{
    $object = ternary
    (
        isObject( $value ) ,
        $value ,
        ternary( isArray( $value ) , first( $value ) , AQL::NULL )
    ) ;

    // The fallback wraps the whole normalisation rather than replacing its last
    // branch : a join variable is an ARRAY, empty when nothing matched, and
    // `FIRST([])` is `null` too — only `NOT_NULL()` around the result catches both.
    return keyValue( $key , $else === null ? $object : notNull( $object , $else ) ) ;
}