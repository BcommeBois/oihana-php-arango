<?php

namespace oihana\arango\db\helpers;

/**
 * Reads an `alt` chain out of a **request slot**, presuming it came from the wire.
 *
 * Called at the point where the chain is read, never later: that is the only place
 * where the origin is still known. The presumption is deliberate — an unmarked
 * chain in a request slot is treated as untrusted, so forgetting to sign one gives
 * the *safe* behaviour (its parameters get bound) rather than a silent hole.
 *
 * - `null` stays `null` — there is no chain to qualify.
 * - A chain signed with {@see trustedAlt()} is **unwrapped** to its bare form, so it
 *   flows on exactly as it did before this mechanism existed.
 * - Anything else is wrapped as a request chain, which {@see alterExpression()}
 *   answers by binding its parameters — or by raising, when no binder was supplied.
 *
 * @param mixed $alt The raw slot content.
 *
 * @return mixed `null`, the bare chain of a signed one, or an {@see AltChain} request wrapper.
 *
 * @package oihana\arango\db\helpers
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
function requestAlt( mixed $alt ) : mixed
{
    if ( $alt === null )
    {
        return null ;
    }

    if ( $alt instanceof AltChain )
    {
        return $alt->trusted ? $alt->chain : $alt ;
    }

    return AltChain::request( $alt ) ;
}
