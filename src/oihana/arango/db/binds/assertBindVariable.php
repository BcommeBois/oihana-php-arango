<?php

namespace oihana\arango\db\binds;

use oihana\exceptions\BindException;

/**
 * Asserts that the provided string is a valid ArangoDB bind variable name.
 *
 * A valid bind variable:
 * - May optionally start with '@'
 * - Must start with a letter (a-zA-Z) or underscore '_'
 * - Subsequent characters can be letters, digits, or underscores
 *
 * Null is considered valid and ignored.
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
 * @param string|null $to The bind variable to validate. Null is allowed.
 *
 * @throws BindException if the string is not a valid bind variable
 *
 * @example
 * ```php
 * assertBindVariable('@userId');   // ✅ no exception
 * assertBindVariable('foo');       // ✅ no exception
 * assertBindVariable('123abc');    // ❌ throws BindException
 * assertBindVariable('@!invalid'); // ❌ throws BindException
 * assertBindVariable(null);        // ✅ no exception
 * ```
 *
 * @package oihana\arango\db\binds
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function assertBindVariable( ?string $to ): void
{
    if ( isset( $to ) && !isBindVariable( $to ) )
    {
        throw new BindException( sprintf( 'Invalid bind variable with the value: "%s"' , $to ) ) ;
    }
}