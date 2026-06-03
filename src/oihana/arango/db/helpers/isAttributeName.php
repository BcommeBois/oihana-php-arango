<?php

namespace oihana\arango\db\helpers;

/**
 * Checks whether a string is a safe AQL attribute name — or nested attribute
 * path — that can be concatenated into a dot-notation accessor such as
 * `doc.<name>` without any risk of AQL injection.
 *
 * A valid name is one or more identifier segments joined by dots, where each
 * segment starts with a letter or underscore and continues with letters, digits
 * or underscores. This is exactly what AQL dot notation accepts unquoted, so any
 * character able to break out of an attribute path (spaces, operators, quotes,
 * parentheses, `-`, `;`, …) is rejected.
 *
 * It is the attribute-path counterpart of {@see isBindVariable()} (which guards
 * bind variable names): use it whenever an untrusted identifier — e.g. a facet
 * sub-field name coming from the URL — is interpolated into a query string.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\isAttributeName;
 *
 * isAttributeName( 'value' );                  // true
 * isAttributeName( '_key' );                   // true
 * isAttributeName( 'breeding.alternateName' ); // true  (nested path)
 * isAttributeName( 'a1.b2.c3' );               // true
 * isAttributeName( 'with space' );             // false
 * isAttributeName( 'a || 1==1' );              // false
 * isAttributeName( 'my-key' );                 // false (hyphen invalid in dot notation)
 * isAttributeName( '.value' );                 // false
 * isAttributeName( 'value.' );                 // false
 * isAttributeName( '1value' );                 // false (a segment cannot start with a digit)
 * isAttributeName( '' );                       // false
 * isAttributeName( 42 );                       // false (not a string)
 * ```
 *
 * @param mixed $value The value to check.
 *
 * @return bool True when `$value` is a safe single or dotted attribute name.
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isAttributeName( mixed $value ): bool
{
    if ( !is_string( $value ) || $value === '' )
    {
        return false ;
    }

    return (bool) preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/' , $value ) ;
}
