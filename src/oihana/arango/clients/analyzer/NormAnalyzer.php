<?php

namespace oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\enums\AnalyzerField ;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;

/**
 * Locale-aware normaliser. Lower-cases / upper-cases the input and optionally strips diacritics.
 * Does NOT tokenise — use {@see TextAnalyzer} when word-boundary tokenisation is needed.
 *
 * Example:
 * ```php
 * $db->createAnalyzer
 * (
 *     'norm_en' ,
 *     new NormAnalyzer( locale : 'en' , accent : false ) ,
 * ) ;
 * ```
 *
 * @package oihana\arango\clients\analyzer
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class NormAnalyzer implements AnalyzerOptions
{
    /**
     * @param string      $locale BCP 47 / ICU locale tag (e.g. `"en"`, `"fr.utf-8"`).
     * @param string|null $case   Case folding strategy (`"lower"`, `"upper"`, `"none"`). Defaults to server's `"lower"`.
     * @param bool|null   $accent Whether to keep diacritics. Defaults to server's `false` (accents removed).
     */
    public function __construct
    (
        public string  $locale ,
        public ?string $case   = null ,
        public ?bool   $accent = null ,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        $properties = [ AnalyzerField::LOCALE => $this->locale ] ;

        if ( $this->case   !== null ) { $properties[ AnalyzerField::CASE   ] = $this->case   ; }
        if ( $this->accent !== null ) { $properties[ AnalyzerField::ACCENT ] = $this->accent ; }

        return
        [
            AnalyzerField::TYPE       => AnalyzerType::NORM ,
            AnalyzerField::PROPERTIES => $properties ,
        ] ;
    }
}
