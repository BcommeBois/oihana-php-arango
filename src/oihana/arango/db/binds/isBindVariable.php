<?php

namespace oihana\arango\db\binds;

/**
 * Checks if the given string is a valid ArangoDB bind variable name.
 *
 * ArangoDB bind variables:
 * - May optionally start with '@'
 * - Must start with a letter (a-zA-Z) or underscore '_'
 * - Subsequent characters can be letters, digits, or underscores
 *
 * Valid examples:
 * - `@userId`
 * - `foo`
 * - `_bar123`
 *
 * Invalid examples:
 * - `123abc`   (starts with a digit)
 * - `@!invalid` (invalid character '!')
 * - `user-id`   (hyphen not allowed)
 *
 * @param string $bindParameter The string to check
 * @return bool True if the string is a valid bind variable name, false otherwise
 *
 * @example
 * ```php
 * isBindVariable('@userId') ;   // true
 * isBindVariable('foo') ;       // true
 * isBindVariable('123abc') ;    // false
 * isBindVariable('@!invalid') ; // false
 * ```
 *
 * @package oihana\arango\db\binds
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isBindVariable( string $bindParameter ): bool
{
    return preg_match('/^@?[a-zA-Z_][a-zA-Z0-9_]*$/', $bindParameter ) === 1 ;
}