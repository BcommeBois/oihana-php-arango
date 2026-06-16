<?php

namespace oihana\arango\db\options\analyzers ;

use oihana\arango\clients\analyzer\AnalyzerOptions ;

/**
 * Declarative definition of a custom ArangoSearch analyzer — the unit the
 * analyzer lifecycle tooling (`analyzerDiff()` / `analyzerSync()`, the
 * `arango:analyzers` action and the `doctor`) reasons about. The analyzer
 * counterpart of {@see \oihana\arango\db\options\indexes\IndexOptions}.
 *
 * It bundles the three pieces a `POST /_api/analyzer` body needs: the
 * server-side `name`, the type-specific `options` (one of
 * {@see \oihana\arango\clients\analyzer\IdentityAnalyzer},
 * {@see \oihana\arango\clients\analyzer\TextAnalyzer},
 * {@see \oihana\arango\clients\analyzer\NormAnalyzer},
 * {@see \oihana\arango\clients\analyzer\StemAnalyzer}) and the optional
 * `features` (entries of {@see \oihana\arango\clients\analyzer\enums\AnalyzerFeature}).
 *
 * Example:
 * ```php
 * new AnalyzerDefinition
 * (
 *     'text_fr_custom' ,
 *     new TextAnalyzer( locale: 'fr.utf-8' , case: 'lower' , accent: false , stemming: true ) ,
 *     [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
 * ) ;
 * ```
 *
 * @package oihana\arango\db\options\analyzers
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
readonly class AnalyzerDefinition
{
    /**
     * @param string             $name     The server-side analyzer name (short form, without the `dbname::` prefix).
     * @param AnalyzerOptions    $options  The type-specific analyzer options.
     * @param array<int, string> $features Optional analyzer features ({@see \oihana\arango\clients\analyzer\enums\AnalyzerFeature}).
     */
    public function __construct
    (
        public string          $name ,
        public AnalyzerOptions $options ,
        public array           $features = [] ,
    )
    {
    }
}
