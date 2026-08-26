<?php

namespace oihana\arango\db\helpers;

use oihana\arango\exceptions\RequestValidationException;
use oihana\exceptions\ValidationException;

/**
 * Asserts that a value is a language code safe to interpolate into a query,
 * throwing when it is not. The language counterpart of
 * {@see assertAttributeName()}, and it exists for the same reason: a language
 * tag names an attribute of the translations object, so it is written verbatim
 * into the query string and can never be bound.
 *
 * Who is blamed depends on where the tag came from, exactly as for an attribute
 * name. A **declared** default language (`Arango::DEFAULT_LANG`, the site or
 * model fallback) is the consumer's own code and no request can fix it: it
 * refuses with a plain {@see ValidationException}, answered with a `500`. A
 * **requested** language (`Arango::LANG`, the `?lang=` parameter) is told it
 * wrote something the API cannot read — a `400`.
 *
 * ⚠ A controller already filters `?lang=` against its supported `languages`
 * whitelist, so a request rarely reaches this guard. A consumer calling the
 * model directly bypasses that controller, which is precisely why the guard
 * lives here too.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\assertLanguageCode;
 *
 * assertLanguageCode( 'fr' );                        // ok
 * assertLanguageCode( 'fr" || 1==1' );               // throws ValidationException
 * assertLanguageCode( $requested , fromRequest: true ); // throws RequestValidationException
 * ```
 *
 * @param mixed $value       The language tag to validate.
 * @param bool  $fromRequest Whether the tag was supplied by the request rather than declared in code.
 *
 * @return void
 *
 * @throws ValidationException When `$value` is not a safe language tag — a
 *                             {@see RequestValidationException} (`400`) when it came from a request.
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function assertLanguageCode( mixed $value , bool $fromRequest = false ): void
{
    if ( !isLanguageCode( $value ) )
    {
        $message = sprintf( 'Invalid language code: "%s"' , is_string( $value ) ? $value : get_debug_type( $value ) ) ;

        throw $fromRequest ? new RequestValidationException( $message ) : new ValidationException( $message ) ;
    }
}
