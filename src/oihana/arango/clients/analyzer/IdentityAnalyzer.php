<?php

namespace oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\enums\AnalyzerField ;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;

/**
 * Pass-through analyzer — emits its input verbatim, with no
 * transformation. Useful as the default analyzer on every link and
 * on every field that does not need language-aware normalisation.
 *
 * Carries no type-specific properties (the `properties` wrapper is
 * still emitted as an empty object so the server's response shape
 * round-trips cleanly).
 *
 * Example:
 * ```php
 * $db->createAnalyzer( 'identity_raw' , new IdentityAnalyzer() ) ;
 * ```
 *
 * @package oihana\arango\clients\analyzer
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class IdentityAnalyzer implements AnalyzerOptions
{
    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        return
        [
            AnalyzerField::TYPE       => AnalyzerType::IDENTITY ,
            AnalyzerField::PROPERTIES => (object) [] ,
        ] ;
    }
}
