<?php

namespace oihana\arango\db\helpers;

/**
 * Check if a string looks like an AQL expression that should not be quoted.
 *
 * Detects:
 * - AQL functions: CONCAT(...), DATE_NOW(), etc. — a string that STARTS with a known function call
 * - Document references: doc.field, user.name, etc.
 * - Bind parameters: @userId, @filter.name, etc.
 * - Collection paths: users/12345, etc.
 * - Already quoted strings ('hello'), booleans and numbers
 *
 * What is NOT detected : compound expressions (`i + 1`, `x.y == 1`, `(1 + 2)`) and
 * parenthesized subqueries (`(FOR x IN things RETURN x)`). The detection decides
 * whether a string is emitted as code or as a quoted value : recognizing these
 * shapes would turn ordinary text into code — AQL keywords are case-insensitive, so
 * `(for sale)` would qualify — and a well-formed subquery coming from data would
 * run. To emit such an expression raw, declare it : `AQL::RAW_KEYS` (per key) or
 * `AQL::RAW_VALUES` (per value) in {@see aqlDocument()}, the `$rawValues` argument of
 * {@see aqlValue()}.
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
 * Already quoted strings (kept as they are)
 * ```php
 * isAQLExpression("'hello'");   // true
 * ```
 *
 * Regular strings (should be quoted)
 * ```php
 * isAQLExpression('"hello"');   // false
 * isAQLExpression('hello');     // false
 * isAQLExpression('user name'); // false
 * ```
 *
 * Compound expressions and subqueries (not detected, declare them raw)
 * ```php
 * isAQLExpression('i + 1');                      // false
 * isAQLExpression('(FOR x IN things RETURN x)'); // false
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