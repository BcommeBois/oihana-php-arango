<?php

namespace oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\enums\AnalyzerField ;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;

/**
 * N-gram analyzer — emits every substring (n-gram) of its input whose
 * length is between `min` and `max` characters, optionally keeping the
 * original token. It is the building block of substring / "as-you-type"
 * autocomplete search: indexing a field with an n-gram analyzer lets a
 * partial term (`ate`) match a longer value (`Atelier`).
 *
 * Unlike the `edgeNgram` option nested inside a {@see TextAnalyzer} (which
 * only emits prefixes), a standalone `ngram` analyzer emits n-grams from
 * every position. It is typically paired with a `text` analyzer on the
 * **same** field (multiple analyzers per field) so the field serves both
 * whole-word search and autocomplete.
 *
 * Example:
 * ```php
 * $db->createAnalyzer
 * (
 *     'autocomplete' ,
 *     new NgramAnalyzer
 *     (
 *         min              : 2 ,
 *         max              : 5 ,
 *         preserveOriginal : true ,
 *         streamType       : 'utf8' ,
 *     ) ,
 *     [
 *         AnalyzerFeature::FREQUENCY ,
 *         AnalyzerFeature::POSITION ,
 *     ] ,
 * ) ;
 * ```
 *
 * @package oihana\arango\clients\analyzer
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.5.0
 */
readonly class NgramAnalyzer implements AnalyzerOptions
{
    /**
     * @param int         $min              Lower bound of the n-gram length window (inclusive).
     * @param int         $max              Upper bound of the n-gram length window (inclusive).
     * @param bool        $preserveOriginal Whether to also keep the original (un-split) token in the output stream.
     * @param string|null $startMarker      String prepended to the input before n-gram emission, so start-of-token n-grams can be distinguished. Defaults to server's empty string.
     * @param string|null $endMarker        String appended to the input before n-gram emission, so end-of-token n-grams can be distinguished. Defaults to server's empty string.
     * @param string|null $streamType       Input encoding: `"binary"` (byte-wise, server default) or `"utf8"` (codepoint-wise).
     */
    public function __construct
    (
        public int     $min ,
        public int     $max ,
        public bool    $preserveOriginal = false ,
        public ?string $startMarker      = null ,
        public ?string $endMarker        = null ,
        public ?string $streamType       = null ,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        $properties =
        [
            AnalyzerField::MIN               => $this->min ,
            AnalyzerField::MAX               => $this->max ,
            AnalyzerField::PRESERVE_ORIGINAL => $this->preserveOriginal ,
        ] ;

        if ( $this->startMarker !== null ) { $properties[ AnalyzerField::START_MARKER ] = $this->startMarker ; }
        if ( $this->endMarker   !== null ) { $properties[ AnalyzerField::END_MARKER   ] = $this->endMarker   ; }
        if ( $this->streamType  !== null ) { $properties[ AnalyzerField::STREAM_TYPE  ] = $this->streamType  ; }

        return
        [
            AnalyzerField::TYPE       => AnalyzerType::NGRAM ,
            AnalyzerField::PROPERTIES => $properties ,
        ] ;
    }
}
