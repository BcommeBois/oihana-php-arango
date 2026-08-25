<?php

namespace oihana\arango\db\helpers;

use oihana\exceptions\ValidationException;

/**
 * Asserts that a string is a safe AQL **variable** name, throwing when it is not.
 *
 * The variable counterpart of {@see assertAttributeName()}, and not
 * interchangeable with it: an attribute name may be a path (`address.city`),
 * a variable name may not — `LET address.city = …` is a syntax error. Use this
 * one before interpolating a declared identifier into a `LET`.
 *
 * @param mixed $value The variable name to validate.
 *
 * @return void
 *
 * @throws ValidationException When `$value` is not a well-formed variable name.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\assertVariableName;
 *
 * assertVariableName( 'authorRef'    ) ; // ok
 * assertVariableName( 'address.city' ) ; // throws
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
function assertVariableName( mixed $value ): void
{
    if ( !isVariableName( $value ) )
    {
        throw new ValidationException( sprintf
        (
            'Invalid AQL variable name: "%s". Expected a single identifier — a letter or underscore, then letters, digits or underscores.' ,
            is_string( $value ) ? $value : get_debug_type( $value ) ,
        )) ;
    }
}
