<?php

namespace oihana\arango\db\helpers;

/**
 * Signs an `alt` chain as authored by the consumer's own code, so its parameters
 * are interpolated as written rather than bound.
 *
 * Only needed where a chain reaches the engine through a **request slot** — today
 * `$init[FilterParam::ALT]`, which a host may also fill programmatically through
 * `InjectFilterTrait::injectFilter()`. Everything read from a model declaration
 * (`Field::ALTERS`, `Facet::ALT`) is trusted by default and needs no signature.
 *
 * Sign a chain only when it must name an expression the request could not supply —
 * another attribute, typically. A chain carrying plain values needs nothing: bound
 * is what you want.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\trustedAlt;
 *
 * // Compare a field to another field — impossible from a request, on purpose.
 * $this->injectFilter( $init , 'name' , $v , alt: trustedAlt( [ 'like' , 'doc.pattern' ] ) ) ;
 * ```
 *
 * @param mixed $chain The chain, in any of the `alt` notations.
 *
 * @return AltChain The signed chain.
 *
 * @package oihana\arango\db\helpers
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
function trustedAlt( mixed $chain ) : AltChain
{
    return AltChain::trusted( $chain ) ;
}
