<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Set the Analyzer for a search expression.
 *
 * Wraps the ArangoDB AQL function `ANALYZER(expr, analyzer)`, which sets the
 * Analyzer used to evaluate the wrapped search expression **and** all the
 * nested functions that accept an Analyzer argument (so it does not have to be
 * repeated). A nested function passing its own Analyzer takes precedence.
 * Only applicable to queries against `arangosearch` Views — with `search-alias`
 * Views and inverted indexes the Analyzer is inferred from the index definition.
 *
 * The `TOKENS()` function is an exception: it always requires its own Analyzer
 * argument, even when wrapped, because it is a regular string function.
 *
 * Example AQL usage:
 * ```aql
 * ANALYZER(PHRASE(doc.text, "foo") OR PHRASE(doc.text, "bar"), "text_en")
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\analyzer;
 * use function oihana\arango\db\functions\search\phrase;
 *
 * $expr = analyzer( phrase( 'doc.text' , 'quick fox' ) , 'text_en' ) ;
 * // 'ANALYZER(PHRASE(doc.text,"quick fox"),"text_en")'
 * ```
 *
 * @param string $expr     Any valid search expression (kept raw).
 * @param string $analyzer Name of the Analyzer (emitted as a quoted string literal).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#analyzer
 * @see boost()
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function analyzer( string $expr , string $analyzer ) : string
{
    return func( SearchFunction::ANALYZER , [ $expr , json_encode( $analyzer ) ] ) ;
}
