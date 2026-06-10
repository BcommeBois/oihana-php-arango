<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Match documents where at least a minimum number of search expressions are true.
 *
 * Wraps the variadic ArangoDB AQL function
 * `MIN_MATCH(expr1, ... exprN, minMatchCount)`. Inside a `SEARCH` operation it
 * is index-accelerated; the same function also exists as a miscellaneous
 * function outside of `SEARCH` (hence the constant living in
 * {@see MiscFunction}).
 *
 * Example AQL usage:
 * ```aql
 * MIN_MATCH(doc.text == "quick", doc.text == "brown", doc.text == "fox", 2)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\minMatch;
 *
 * $expr = minMatch( [ 'doc.text == "quick"' , 'doc.text == "brown"' , 'doc.text == "fox"' ] , 2 ) ;
 * // 'MIN_MATCH(doc.text == "quick",doc.text == "brown",doc.text == "fox",2)'
 * ```
 *
 * @param array $expressions   The candidate search expressions (kept raw).
 * @param int   $minMatchCount Minimum number of expressions that must be satisfied.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#min_match
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function minMatch( array $expressions , int $minMatchCount ) : string
{
    return func( MiscFunction::MIN_MATCH , [ ...$expressions , $minMatchCount ] ) ;
}
