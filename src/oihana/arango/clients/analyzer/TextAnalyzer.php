<?php

namespace oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\enums\AnalyzerField ;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;

/**
 * Full-text analyzer — tokenises on word boundaries, optionally lower-cases, removes stopwords,
 * applies stemming and accent folding, and optionally emits edge n-grams for prefix search.
 *
 * Combined with the {@see \oihana\arango\clients\analyzer\enums\AnalyzerFeature::FREQUENCY}
 * and {@see \oihana\arango\clients\analyzer\enums\AnalyzerFeature::POSITION}
 * features, it is the building block of every ArangoSearch view
 * intended for `BM25()` / `PHRASE()` queries.
 *
 * Example:
 * ```php
 * $db->createAnalyzer
 * (
 *     'text_fr' ,
 *     new TextAnalyzer
 *     (
 *         locale    : 'fr' ,
 *         case      : 'lower' ,
 *         accent    : false ,
 *         stemming  : true ,
 *         stopwords : [ 'le' , 'la' , 'les' ] ,
 *         edgeNgram : [ 'min' => 2 , 'max' => 5 , 'preserveOriginal' => true ] ,
 *     ) ,
 *     [
 *         AnalyzerFeature::FREQUENCY ,
 *         AnalyzerFeature::POSITION ,
 *         AnalyzerFeature::NORM ,
 *     ] ,
 * ) ;
 * ```
 *
 * @package oihana\arango\clients\analyzer
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class TextAnalyzer implements AnalyzerOptions
{
    /**
     * @param string                       $locale        BCP 47 / ICU locale tag (e.g. `"en"`, `"fr.utf-8"`).
     * @param string|null                  $case          Case folding strategy (`"lower"`, `"upper"`, `"none"`). Defaults to server's `"lower"`.
     * @param bool|null                    $accent        Whether to keep diacritics. Defaults to server's `false` (accents removed).
     * @param bool|null                    $stemming      Whether to apply Snowball stemming. Defaults to server's `true`.
     * @param array<int, string>|null      $stopwords     Inline list of stopwords to drop from the token stream.
     * @param string|null                  $stopwordsPath Path to a newline-separated stopwords file (resolved server-side).
     * @param array<string, int|bool>|null $edgeNgram     Edge n-gram options: `min` / `max` / `preserveOriginal`. Setting `min > 0` enables edge n-gram emission for prefix search.
     */
    public function __construct
    (
        public string  $locale ,
        public ?string $case          = null ,
        public ?bool   $accent        = null ,
        public ?bool   $stemming      = null ,
        public ?array  $stopwords     = null ,
        public ?string $stopwordsPath = null ,
        public ?array  $edgeNgram     = null ,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        $properties = [ AnalyzerField::LOCALE => $this->locale ] ;

        if ( $this->case          !== null ) { $properties[ AnalyzerField::CASE           ] = $this->case          ; }
        if ( $this->accent        !== null ) { $properties[ AnalyzerField::ACCENT         ] = $this->accent        ; }
        if ( $this->stemming      !== null ) { $properties[ AnalyzerField::STEMMING       ] = $this->stemming      ; }
        if ( $this->stopwords     !== null ) { $properties[ AnalyzerField::STOPWORDS      ] = array_values( $this->stopwords ) ; }
        if ( $this->stopwordsPath !== null ) { $properties[ AnalyzerField::STOPWORDS_PATH ] = $this->stopwordsPath ; }
        if ( $this->edgeNgram     !== null ) { $properties[ AnalyzerField::EDGE_NGRAM     ] = $this->edgeNgram     ; }

        return
        [
            AnalyzerField::TYPE       => AnalyzerType::TEXT ,
            AnalyzerField::PROPERTIES => $properties ,
        ] ;
    }
}
