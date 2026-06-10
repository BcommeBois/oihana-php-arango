<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Override the boost value of a search sub-expression.
 *
 * Wraps the ArangoDB AQL function `BOOST(expr, boost)`. The boost value is made
 * available to scorer functions ({@see bm25()}, {@see tfidf()}) so that matches
 * of the wrapped sub-expression weigh more (or less) in the final score. The
 * default boost of any search context is `1.0`.
 *
 * Example AQL usage:
 * ```aql
 * ANALYZER(BOOST(doc.text == "foo", 2.5) OR doc.text == "bar", "text_en")
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\boost;
 *
 * $expr = boost( 'doc.name == "wood"' , 2.5 ) ;
 * // 'BOOST(doc.name == "wood",2.5)'
 * ```
 *
 * @param string    $expr  Any valid search expression (kept raw).
 * @param float|int $boost Numeric boost value.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#boost
 * @see analyzer()
 * @see bm25()
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function boost( string $expr , float|int $boost ) : string
{
    return func( SearchFunction::BOOST , [ $expr , $boost ] ) ;
}
