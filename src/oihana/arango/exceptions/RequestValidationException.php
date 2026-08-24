<?php

namespace oihana\arango\exceptions;

use oihana\enums\http\HttpStatusCode;
use oihana\exceptions\ValidationException;

/**
 * A refusal the **caller** can act on: their request is malformed.
 *
 * The reading layer does not choose an HTTP status from the *type* of an
 * exception, but from the number carried on it — `HttpStatusCode::fromException()`
 * relays `getCode()` when it is a valid status, and answers `500` otherwise. That
 * mechanism was written for {@see \oihana\arango\clients\exceptions\ArangoException},
 * which exposes the status the server itself returned, and it serves it well.
 *
 * The refusals this library writes on its own never carried a number, so all of
 * them surfaced as `500 Internal Server Error` — "the server is broken" — for
 * faults like a mistyped `quant`, an unknown facet aggregator or an unsupported
 * operator inside a `match`. The messages were already written for whoever has to
 * fix the URL (several of them enumerate the accepted values); only the status
 * disagreed. And a `500` is not read like a `400`: clients replay it, monitoring
 * pages on it, and the developer on the other end goes looking at the
 * infrastructure instead of re-reading their query.
 *
 * This type carries `400` by construction, so the choice is made by **naming the
 * fault** rather than by remembering to pass a number:
 *
 * - the caller wrote something the API cannot understand → this exception ;
 * - the consumer's own code or declaration is wrong → a plain
 *   {@see ValidationException}, which keeps surfacing as `500`, because no URL
 *   will ever fix it.
 *
 * It extends `ValidationException`, so every existing `catch` keeps catching it.
 *
 * @package oihana\arango\exceptions
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
class RequestValidationException extends ValidationException
{
    /**
     * @param string $message The refusal, written for whoever has to fix the request. It is handed
     *                        back to the caller verbatim, so it names what they sent and nothing
     *                        else — never a protected field, never a fragment of the query.
     */
    public function __construct( string $message )
    {
        parent::__construct( $message , HttpStatusCode::BAD_REQUEST ) ;
    }
}
