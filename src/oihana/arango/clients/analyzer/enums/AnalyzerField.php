<?php

namespace oihana\arango\clients\analyzer\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * JSON field names exchanged with the ArangoDB analyzer API
 * (`/_api/analyzer`), on both the request side (body of
 * `POST /_api/analyzer`) and the response side (wrapper of
 * `GET /_api/analyzer/{name}` and entries of
 * `GET /_api/analyzer?force=true`).
 *
 * Two families coexist:
 * - **Top-level fields** (`name`, `type`, `features`, `properties`)
 *   that frame every analyzer payload regardless of its type,
 * - **Type-specific properties** (`locale`, `case`, `accent`,
 *   `stemming`, `stopwords`, `stopwordsPath`, `edgeNgram`, `min`,
 *   `max`, `preserveOriginal`, `startMarker`, `endMarker`,
 *   `streamType`) that nest inside the `properties` wrapper for
 *   `text`, `norm`, `stem` and `ngram` analyzers.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/analyzers/
 *
 * @package oihana\arango\clients\analyzer\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class AnalyzerField
{
    use ConstantsTrait ;

    /**
     * Whether the analyzer should keep diacritics on the input
     * (`text` / `norm` only).
     */
    public const string ACCENT = 'accent' ;

    /**
     * Case folding strategy applied to the input (`text` / `norm`
     * only). Recognised values: `"lower"`, `"upper"`, `"none"`.
     */
    public const string CASE = 'case' ;

    /**
     * Edge n-gram options nested inside the `properties` of a `text`
     * analyzer — carries `min`, `max`, `preserveOriginal` sub-fields.
     */
    public const string EDGE_NGRAM = 'edgeNgram' ;

    /**
     * String appended to the end of the input before n-gram emission
     * (`ngram` only), so end-of-token n-grams can be distinguished.
     * Lives at the top level of the `ngram` `properties`.
     */
    public const string END_MARKER = 'endMarker' ;

    /**
     * List of analyzer feature toggles — entries of {@see AnalyzerFeature}.
     * Top-level field on every analyzer payload.
     */
    public const string FEATURES = 'features' ;

    /**
     * Upper bound of the n-gram window (inclusive). Lives under the
     * {@see self::EDGE_NGRAM} wrapper for a `text` analyzer, or at the
     * top level of the `properties` for an `ngram` analyzer.
     */
    public const string MAX = 'max' ;

    /**
     * Lower bound of the n-gram window (inclusive). Lives under the
     * {@see self::EDGE_NGRAM} wrapper for a `text` analyzer, or at the
     * top level of the `properties` for an `ngram` analyzer.
     */
    public const string MIN = 'min' ;

    /**
     * BCP 47 / ICU locale tag (e.g. `"en"`, `"fr.utf-8"`) driving
     * the language-aware behaviour of the analyzer (`text` / `norm`
     * / `stem`).
     */
    public const string LOCALE = 'locale' ;

    /**
     * Top-level analyzer name. Must be prefixed with the database
     * name when shared across databases (`mydb::myanalyzer`).
     */
    public const string NAME = 'name' ;

    /**
     * Whether the n-gram emitter should also keep the original
     * (un-trimmed) token in the output stream. Lives under the
     * {@see self::EDGE_NGRAM} wrapper for a `text` analyzer, or at the
     * top level of the `properties` for an `ngram` analyzer.
     */
    public const string PRESERVE_ORIGINAL = 'preserveOriginal' ;

    /**
     * Wrapper field carrying the type-specific options of an
     * analyzer. Always an object — empty (`{}`) for the
     * {@see AnalyzerType::IDENTITY} analyzer.
     */
    public const string PROPERTIES = 'properties' ;

    /**
     * String prepended to the start of the input before n-gram
     * emission (`ngram` only), so start-of-token n-grams can be
     * distinguished. Lives at the top level of the `ngram` `properties`.
     */
    public const string START_MARKER = 'startMarker' ;

    /**
     * List of stopwords to drop from the token stream (`text` only).
     */
    public const string STOPWORDS = 'stopwords' ;

    /**
     * Filesystem path to a newline-separated stopwords file (`text`
     * only). The path is resolved server-side.
     */
    public const string STOPWORDS_PATH = 'stopwordsPath' ;

    /**
     * Whether the `text` analyzer should apply Snowball-style
     * stemming on the tokens it emits.
     */
    public const string STEMMING = 'stemming' ;

    /**
     * Input encoding the `ngram` analyzer operates on — `"binary"`
     * (byte-wise, the server default) or `"utf8"` (codepoint-wise).
     * Lives at the top level of the `ngram` `properties`.
     */
    public const string STREAM_TYPE = 'streamType' ;

    /**
     * Wrapper field carrying the list of analyzers in the response
     * of `GET /_api/analyzer`.
     */
    public const string RESULT = 'result' ;

    /**
     * Analyzer type discriminator — entries of {@see AnalyzerType}.
     */
    public const string TYPE = 'type' ;
}
