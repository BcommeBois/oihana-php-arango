<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\core\arrays\isAssociative;

/**
 * Transform a PHP value into an AQL-compatible expression.
 *
 * Automatically detects AQL functions using pattern matching and treats them as raw expressions.
 * Also supports manual raw value specification for edge cases.
 *
 * String handling flow:
 * ```
 *        +--------------------------+
 *        |      Is $val a string?   |
 *        +-----------+--------------+
 *                    |
 *                   No
 *                    |--> return $val as-is or throw (non-string)
 *                    |
 *                   Yes
 *                    v
 *        +--------------------------+
 *        |   Is $val in rawValues?  |
 *        +-----------+--------------+
 *                    |
 *                Yes |--> return $val (raw)
 *                    |
 *                   No
 *                    v
 *        +--------------------------+
 *        |   Matches AQL function?  |
 *        | CONCAT(...), DATE_NOW()  |
 *        +-----------+--------------+
 *                    |
 *                Yes |--> return $val (raw)
 *                    |
 *                   No
 *                    v
 *        +--------------------------+
 *        | Matches AQL pattern?     |
 *        | doc.field, @bind, col/key|
 *        +-----------+--------------+
 *                    |
 *                Yes |--> return $val (raw)
 *                    |
 *                   No
 *                   v
 *        +--------------------------+
 *        |   Regular string         |
 *        |   Escape and quote       |
 *        |   return "'val'"         |
 *        +--------------------------+
 * ```
 *
 * @param mixed $value The PHP value to transform
 * @param array $rawValues Optional list of specific values to treat as raw AQL expressions
 *
 * @return string AQL expression representing the value
 *
 * @throws UnsupportedOperationException If the value type is unsupported.
 *
 * @example
 * Basic usage
 * ```php
 * echo aqlValue('hello');
 * // 'hello'
 *
 * echo aqlValue(42);
 * // 42
 *
 * echo aqlValue(['name' => 'John', 'age' => 30]);
 * // {name:'John',age:30}
 * ```
 *
 * @example
 * Automatic AQL function detection
 * ```php
 * echo aqlValue('CONCAT("user_", doc.id)');
 * // CONCAT("user_", doc.id)
 *
 * echo aqlValue('LENGTH(doc.name)');
 * // LENGTH(doc.name)
 *
 * echo aqlValue('DATE_NOW()');
 * // DATE_NOW()
 *
 * echo aqlValue('UPPER(user.firstName)');
 * // UPPER(user.firstName)
 * ```
 *
 * @example
 * Document references and bind parameters
 * ```php
 * echo aqlValue('doc._id');
 * // doc._id
 *
 * echo aqlValue('user.name');
 * // user.name
 *
 * echo aqlValue('@userId');
 * // @userId
 *
 * echo aqlValue('users/12345');
 * // users/12345
 * ```
 *
 * @example
 * Complex document with automatic detection
 * ```php
 * $data =
 * [
 *     '_key' => 'CONCAT("user_", doc.id)',
 *     '_from' => 'users/@userId',
 *     '_to' => 'posts/12345',
 *     'name' => 'Regular string',
 *     'length' => 'LENGTH(doc.content)',
 *     'createdAt' => 'DATE_NOW()',
 *     'reference' => 'doc.originalId'
 * ];
 *
 * echo aqlValue($data);
 * // {_key:CONCAT("user_", doc.id),_from:users/@userId,_to:posts/12345,name:'Regular string',length:LENGTH(doc.content),createdAt:DATE_NOW(),reference:doc.originalId}
 * ```
 *
 * @example
 * Manual raw values for edge cases
 * ```php
 * // If a string looks like normal text but should be raw
 * echo aqlValue('some_custom_variable', ['some_custom_variable']);
 * // some_custom_variable
 *
 * // Mix of auto-detection + manual raw
 * $data = [
 *     '_key' => 'CONCAT("test")',        // Auto-detected
 *     'customVar' => 'my_aql_variable'   // Requires rawValues
 * ];
 * echo aqlValue($data, ['my_aql_variable']);
 * // {_key:CONCAT("test"),customVar:my_aql_variable}
 * ```
 *
 * @example
 * What gets auto-detected as AQL
 * ```php
 * // ✅ Functions: WORD(...)
 * aqlValue('CONCAT("a", "b")');          // CONCAT("a", "b")
 * aqlValue('DATE_FORMAT(doc.date)');     // DATE_FORMAT(doc.date)
 *
 * // ✅ Document/collection references: word.word
 * aqlValue('doc.name');                  // doc.name
 * aqlValue('user.profile');              // user.profile
 *
 * // ✅ Bind parameters: @word
 * aqlValue('@userId');                   // @userId
 * aqlValue('@filter.name');              // @filter.name
 *
 * // ✅ Collection paths: word/word
 * aqlValue('users/12345');               // users/12345
 * aqlValue('posts/abc-def');             // posts/abc-def
 *
 * // ❌ Regular strings (quoted)
 * aqlValue('just text');                 // 'just text'
 * aqlValue('user name');                 // 'user name'
 * ```
 *
 * @package oihana\arango\db\helpers
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
function aqlValue( mixed $value , array $rawValues = [] ): string
{
    if ( is_string( $value ) )
    {
        if ( in_array( $value , $rawValues , true ) )
        {
            return $value ; // Check manual raw values first
        }

        if ( isAQLExpression( $value ) )
        {
            return $value;
        }

        return "'" . str_replace("'", "\\'", $value) . "'";
    }

    if ( is_bool( $value ) )
    {
        return $value ? 'true' : 'false';
    }

    if ( is_null( $value ) )
    {
        return 'null';
    }

    if ( is_numeric( $value ) )
    {
        return (string) $value ;
    }

    if ( is_array( $value ) )
    {
        if( isAssociative( $value ) )
        {
            return aqlDocument( $value, [ AQL::RAW_VALUES => $rawValues ] ) ;
        }
        return '[' . implode(',', array_map( fn( $v ) => aqlValue( $v, $rawValues ), $value ) ) . ']' ;
    }

    if ( is_object( $value ) )
    {
        return aqlDocument( get_object_vars( $value ) , [ AQL::RAW_VALUES => $rawValues ] ) ;
    }

    throw new UnsupportedOperationException
    (
        'Unsupported type for aqlValue(): ' . gettype( $value )
    );
}