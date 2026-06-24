<?php

namespace oihana\arango\clients\analyzer ;

use InvalidArgumentException ;

use oihana\arango\clients\analyzer\enums\AnalyzerField ;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;

/**
 * Pipeline analyzer — runs an **ordered** chain of sub-analyzers, each fed
 * the output of the previous one. It is the typed way to compose analyzers
 * the server otherwise only exposes individually ({@see NormAnalyzer},
 * {@see NgramAnalyzer}, {@see StemAnalyzer}, …); without it, the only escape
 * hatch was an untyped `new RawAnalyzer( 'pipeline' , … )`.
 *
 * **Why it matters — case-/accent-insensitive autocomplete.** A standalone
 * `ngram` analyzer normalises **neither** case **nor** accents: indexing a
 * field stored in upper case (`"L'ABSIE"`, `"ANGLET"`) yields upper-case
 * n-grams, while a user typing `l'ab` in lower case produces lower-case
 * n-grams — the two token streams never meet and the autocomplete silently
 * matches nothing. ArangoDB offers no per-type "normalise first" switch on
 * `ngram`; the clean fix is a `pipeline` that runs a {@see NormAnalyzer}
 * (lower-case + accent fold) **before** the {@see NgramAnalyzer}, so both the
 * indexed values and the query are folded to the same form before the split.
 *
 * The order of {@see $pipeline} is significant — `norm` must come **before**
 * `ngram`, never the reverse.
 *
 * Example — the `norm` → `ngram` autocomplete pipeline:
 * ```php
 * use oihana\arango\clients\analyzer\NgramAnalyzer ;
 * use oihana\arango\clients\analyzer\NormAnalyzer ;
 * use oihana\arango\clients\analyzer\PipelineAnalyzer ;
 * use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;
 *
 * $db->createAnalyzer
 * (
 *     'autocomplete' ,
 *     new PipelineAnalyzer
 *     ([
 *         new NormAnalyzer ( locale : 'fr' , case : 'lower' , accent : false ) , // 1. fold case + accents
 *         new NgramAnalyzer( min : 3 , max : 5 , preserveOriginal : true ) ,      // 2. then split
 *     ]) ,
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
readonly class PipelineAnalyzer implements AnalyzerOptions
{
    /**
     * @param array<int, AnalyzerOptions> $pipeline The ordered chain of sub-analyzers; each member is itself an
     *                                              {@see AnalyzerOptions} value object, run in declaration order
     *                                              (e.g. `[ new NormAnalyzer(…) , new NgramAnalyzer(…) ]`).
     *
     * @throws InvalidArgumentException When the pipeline is empty, or any member is not an {@see AnalyzerOptions}.
     */
    public function __construct( public array $pipeline )
    {
        if ( $pipeline === [] )
        {
            throw new InvalidArgumentException( 'PipelineAnalyzer requires a non-empty, ordered list of sub-analyzers.' ) ;
        }

        foreach ( $pipeline as $index => $member )
        {
            if ( !$member instanceof AnalyzerOptions )
            {
                throw new InvalidArgumentException
                (
                    sprintf
                    (
                        'PipelineAnalyzer member at index %d must be an instance of %s, %s given.' ,
                        $index ,
                        AnalyzerOptions::class ,
                        get_debug_type( $member ) ,
                    ) ,
                ) ;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        $pipeline = [] ;

        foreach ( $this->pipeline as $member )
        {
            $pipeline[] = $member->toArray() ;
        }

        return
        [
            AnalyzerField::TYPE       => AnalyzerType::PIPELINE ,
            AnalyzerField::PROPERTIES => [ AnalyzerField::PIPELINE => $pipeline ] ,
        ] ;
    }
}
