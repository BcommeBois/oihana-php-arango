<?php

namespace oihana\arango\db\helpers;

use oihana\exceptions\ValidationException;

/**
 * Asserts that a string is a safe AQL attribute name (or nested attribute path),
 * throwing when it is not. This is the attribute-path counterpart of
 * {@see assertBindVariable()}: use it before interpolating an untrusted
 * identifier (e.g. a facet sub-field name from the URL) into a `doc.<name>`
 * accessor, to guarantee no AQL injection is possible through the path.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\assertAttributeName;
 *
 * assertAttributeName( 'breeding.alternateName' ); // ok
 * assertAttributeName( 'a || 1==1' );              // throws ValidationException
 * ```
 *
 * @param mixed $value The attribute name to validate.
 *
 * @return void
 *
 * @throws ValidationException When `$value` is not a safe attribute name.
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function assertAttributeName( mixed $value ): void
{
    if ( !isAttributeName( $value ) )
    {
        throw new ValidationException( sprintf( 'Invalid AQL attribute name: "%s"' , is_string( $value ) ? $value : get_debug_type( $value ) ) ) ;
    }
}
