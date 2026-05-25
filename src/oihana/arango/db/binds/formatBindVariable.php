<?php

namespace oihana\arango\db\binds;

use oihana\enums\Char;
use function oihana\core\strings\wrap;

/**
 * Formats a string as an ArangoDB bind variable.
 *
 * - If the name already starts with `@`, it is wrapped (using {@see wrap()}) to escape it.
 * - If `$isCollection` is true, the resulting bind variable is prefixed with `@@` for collection bind variables.
 * - Otherwise, it is prefixed with a single `@`.
 *
 * @param string $name          The name of the bind variable.
 * @param bool   $isCollection  Whether this is a collection bind variable (prefixed with @@).
 *
 * @return string The properly formatted bind variable name.
 *
 * @example
 * ```php
 * formatBindVariable('userId');
 * // returns '@userId'
 *
 * formatBindVariable('@userId');
 * // returns '@`@userId`'
 *
 * formatBindVariable('users', true);
 * // returns '@@users'
 *
 * formatBindVariable('@users', true);
 * // returns '@@`@users`'
 * ```
 *
 * @package oihana\arango\db\binds
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function formatBindVariable( string $name , bool $isCollection = false ):string
{
    if ( stripos( $name , Char::AT_SIGN ) === 0 )
    {
        $name = wrap( $name ) ;
    }
    return ( $isCollection ? Char::AT_SIGN . Char::AT_SIGN : Char::AT_SIGN ) . $name ;
}