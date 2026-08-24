<?php

namespace oihana\arango\db\helpers;

use oihana\arango\exceptions\RequestValidationException;
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
 * Most call sites guard a **developer-declared** name — a defensive check on a
 * value the consumer's own code supplied, which no request can fix: those refuse
 * with a plain {@see ValidationException}, which the reading layer answers with a
 * `500`. The handful that guard a name coming from the wire (a facet sub-field, a
 * `pluck` field, the key of an inline `match`) pass `$fromRequest`, so the caller
 * is told they wrote something the API cannot read — a `400`. Same check, same
 * message; only who is being blamed changes.
 *
 * @param mixed $value       The attribute name to validate.
 * @param bool  $fromRequest Whether the name was supplied by the request rather than declared in code.
 *
 * @return void
 *
 * @throws ValidationException When `$value` is not a safe attribute name — a
 *                             {@see RequestValidationException} (`400`) when it came from a request.
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function assertAttributeName( mixed $value , bool $fromRequest = false ): void
{
    if ( !isAttributeName( $value ) )
    {
        $message = sprintf( 'Invalid AQL attribute name: "%s"' , is_string( $value ) ? $value : get_debug_type( $value ) ) ;

        throw $fromRequest ? new RequestValidationException( $message ) : new ValidationException( $message ) ;
    }
}
