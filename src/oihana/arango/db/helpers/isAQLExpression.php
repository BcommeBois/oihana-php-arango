<?php

namespace oihana\arango\db\helpers;

/**
 * Check if a string looks like an AQL expression that should not be quoted.
 *
 * Detects:
 * - AQL functions: CONCAT(...), DATE_NOW(), etc.
 * - Document references: doc.field, user.name, etc.
 * - Bind parameters:
 *
 * @param mixed $value The expression value to check
 *
 * @return bool True if it looks like an AQL expression
 *
 * @example
 * AQL functions
 * ```php
 * isAQLExpression('CONCAT("a","b")'); // true
 * isAQLExpression('DATE_NOW()');      // true
 * ```
 *
 * Document references
 * ```php
 * isAQLExpression('doc.name');        // true
 * isAQLExpression('user.profile');    // true
 * ```
 *
 * Bind parameters
 * ```php
 * isAQLExpression('@userId');         // true
 * isAQLExpression('@filter.name');    // true
 * ```
 *
 * Collection paths
 * ```php
 * isAQLExpression('users/12345');     // true
 * isAQLExpression('posts/abc-def');   // true
 * ```
 *
 * Regular strings (should be quoted)
 * ```php
 * isAQLExpression("'hello'");   // true
 *
 * isAQLExpression('"hello"');   // false
 * isAQLExpression('hello');     // false
 * isAQLExpression('user name'); // false
 * ```
 */
function isAQLExpression( mixed $value ) :bool
{
    if ( !is_string( $value ) )
    {
        return false ;
    }

    $value = trim( $value ) ;

    if ( $value === '' )
    {
        return false ;
    }

    // Strings already quoted (single or double) are considered AQL expressions
    if ( preg_match( "/^'.*'$/s" , $value ) )
    {
        return true ;
    }

    if ( in_array( strtolower( $value ) , [ 'true' , 'false' ] , true ) || is_numeric( $value ) )
    {
        return true ;
    }

    if( isAQLFunction( $value ) )
    {
        return true ;
    }

    $patterns =
    [
        // Document/collection references: word.word, word.word.word, etc.
        '/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_\.]*$/',
        // Bind parameters: @word, @word.word, etc.
        '/^@[a-zA-Z_][a-zA-Z0-9_\.]*$/',
        // Collection document paths: collection/key
        '/^[a-zA-Z_][a-zA-Z0-9_]*\/[a-zA-Z0-9_\-]+$/'
    ];

    return array_any( $patterns , fn( $pattern ) => preg_match( $pattern , $value ) ) ;
}