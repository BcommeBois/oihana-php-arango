<?php

namespace oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\enums\AnalyzerField ;

/**
 * Raw, type-agnostic analyzer options — carries a verbatim `type`
 * discriminator and `properties` map instead of the named arguments of a
 * typed value object ({@see TextAnalyzer}, {@see NormAnalyzer}, {@see StemAnalyzer},
 * {@see IdentityAnalyzer}).
 *
 * It is the round-trip companion of the typed analyzers: where those build
 * their `properties` from named constructor arguments, a `RawAnalyzer` simply
 * re-emits a `properties` array obtained elsewhere — typically dumped from an
 * existing {@see AnalyzerOptions::toArray()}. That is what the
 * `arango:analyzers --fix` action writes into a repair migration, so the
 * migration body stays a flat literal (`new RawAnalyzer( 'text' , [ … ] )`)
 * instead of reconstructing the original typed constructor call.
 *
 * Like {@see IdentityAnalyzer}, an empty `properties` map round-trips as an
 * empty object (`{}`), the shape the server expects.
 *
 * Example:
 * ```php
 * new RawAnalyzer( 'text' , [ 'locale' => 'fr.utf-8' , 'case' => 'lower' , 'accent' => false ] ) ;
 * ```
 *
 * @package oihana\arango\clients\analyzer
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
readonly class RawAnalyzer implements AnalyzerOptions
{
    /**
     * @param string               $type       The analyzer type discriminator ({@see \oihana\arango\clients\analyzer\enums\AnalyzerType}).
     * @param array<string, mixed> $properties The type-specific properties; an empty array round-trips as an empty object (`{}`).
     */
    public function __construct
    (
        public string $type ,
        public array  $properties = [] ,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        return
        [
            AnalyzerField::TYPE       => $this->type ,
            AnalyzerField::PROPERTIES => $this->properties === [] ? (object) [] : $this->properties ,
        ] ;
    }
}
