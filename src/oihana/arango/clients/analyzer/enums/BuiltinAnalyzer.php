<?php

namespace oihana\arango\clients\analyzer\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Built-in (server-provided) ArangoSearch analyzer **names**.
 *
 * Unlike {@see AnalyzerType} — which is the `type` discriminator used when
 * *creating* an analyzer (`identity`, `norm`, `stem`, `text`) — these are the
 * *names* of the stock analyzers ArangoDB ships out of the box, referenced when
 * *querying* or *linking*:
 *
 * - {@see \oihana\arango\models\enums\Search::ANALYZER} in a model search,
 * - the `analyzers` of an {@see \oihana\arango\clients\view\ArangoSearchLink},
 * - {@see \oihana\arango\db\functions\search\phrase()} / {@see \oihana\arango\db\functions\search\analyzer()},
 * - {@see \oihana\arango\db\functions\strings\tokens()}.
 *
 * The `text_*` set is fixed by ArangoDB; a *custom* analyzer created with
 * {@see \oihana\arango\clients\Database::createAnalyzer()} keeps a free-form
 * name and is — by design — not covered here. Use {@see self::includes()} to
 * tell a known built-in from a custom name.
 *
 * @example
 * ```php
 * use oihana\arango\clients\analyzer\enums\BuiltinAnalyzer;
 *
 * Search::ANALYZER => BuiltinAnalyzer::TEXT_FR ; // instead of the magic string 'text_fr'
 *
 * new ArangoSearchLink( analyzers : [ BuiltinAnalyzer::TEXT_EN ] ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/analyzers/#built-in-analyzers
 *
 * @package oihana\arango\clients\analyzer\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class BuiltinAnalyzer
{
    use ConstantsTrait ;

    /**
     * Pass-through analyzer — emits its input verbatim. Shares its name with
     * the {@see AnalyzerType::IDENTITY} type and is always present server-side.
     */
    public const string IDENTITY = 'identity' ;

    /** German full-text analyzer. */
    public const string TEXT_DE = 'text_de' ;

    /** English full-text analyzer. */
    public const string TEXT_EN = 'text_en' ;

    /** Spanish full-text analyzer. */
    public const string TEXT_ES = 'text_es' ;

    /** Finnish full-text analyzer. */
    public const string TEXT_FI = 'text_fi' ;

    /** French full-text analyzer. */
    public const string TEXT_FR = 'text_fr' ;

    /** Italian full-text analyzer. */
    public const string TEXT_IT = 'text_it' ;

    /** Dutch full-text analyzer. */
    public const string TEXT_NL = 'text_nl' ;

    /** Norwegian full-text analyzer. */
    public const string TEXT_NO = 'text_no' ;

    /** Portuguese full-text analyzer. */
    public const string TEXT_PT = 'text_pt' ;

    /** Russian full-text analyzer. */
    public const string TEXT_RU = 'text_ru' ;

    /** Swedish full-text analyzer. */
    public const string TEXT_SV = 'text_sv' ;

    /** Chinese full-text analyzer. */
    public const string TEXT_ZH = 'text_zh' ;
}
