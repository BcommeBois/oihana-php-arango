<?php

namespace oihana\arango\db\helpers;

/**
 * An `alt` transformation chain that remembers **who supplied it**.
 *
 * The `alt` engine serves two callers that cannot be told apart once the chain is
 * a bare array: a model declaration (`Field::ALTERS`, `Facet::ALT`) written in the
 * consumer's own code, and a request (`?filter=`, `?group=`, `?facets=`) written by
 * whoever is on the other end of the wire. They need opposite treatments — a
 * declaration may name another field (`['like','doc.pattern']`, interpolated as
 * written), a request may only supply a **value** (bound, so it can never become
 * grammar).
 *
 * Wrapping the chain is what makes the difference survive: the mark travels with
 * the data, so a call site that forgets to pass `$init` cannot strip it, and
 * {@see alterExpression()} raises instead of silently falling back to interpolation.
 *
 * Build one through {@see trustedAlt()} or {@see requestAlt()} rather than directly.
 *
 * @package oihana\arango\db\helpers
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
final readonly class AltChain
{
    private function __construct
    (
        /** The wrapped chain, in any of the `alt` notations. */
        public mixed $chain ,

        /** Whether the chain was authored by the consumer's code rather than by a request. */
        public bool $trusted ,
    ) {}

    /**
     * A chain the consumer's own code authored: interpolated as written.
     */
    public static function trusted( mixed $chain ) : self
    {
        return new self( $chain , true ) ;
    }

    /**
     * A chain that arrived with a request: its parameters are bound, never inlined.
     */
    public static function request( mixed $chain ) : self
    {
        return new self( $chain , false ) ;
    }
}
