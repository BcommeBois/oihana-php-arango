<?php

namespace oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\enums\AnalyzerField ;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;

/**
 * Locale-aware stemmer. Reduces inflected forms of a word to a
 * common root (e.g. `running` → `run`). Single-token input only —
 * compose with a tokenising analyzer upstream when working on full
 * sentences.
 *
 * Example:
 * ```php
 * $db->createAnalyzer
 * (
 *     'stem_en' ,
 *     new StemAnalyzer( locale : 'en' ) ,
 * ) ;
 * ```
 *
 * @package oihana\arango\clients\analyzer
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class StemAnalyzer implements AnalyzerOptions
{
    /**
     * @param string $locale BCP 47 / ICU locale tag (e.g. `"en"`, `"fr.utf-8"`).
     */
    public function __construct( public string $locale )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        return
        [
            AnalyzerField::TYPE       => AnalyzerType::STEM ,
            AnalyzerField::PROPERTIES => [ AnalyzerField::LOCALE => $this->locale ] ,
        ] ;
    }
}
