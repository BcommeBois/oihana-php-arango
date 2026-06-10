<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Match documents containing a phrase — tokens in the given order.
 *
 * Wraps the ArangoDB AQL function `PHRASE(path, phrasePart, analyzer)`.
 * The phrase can be:
 *
 * - a **string** — the simple form, emitted as a quoted string literal;
 * - an **array** — the advanced AQL array form, emitted with `json_encode`,
 *   mirroring the official syntax one-to-one: string tokens are quoted,
 *   integers act as `skipTokens` wildcards, and associative arrays become
 *   object tokens (`{IN_RANGE: …}`, `{LEVENSHTEIN_MATCH: …}`, `{STARTS_WITH: …}`,
 *   `{TERM: …}`, `{TERMS: …}`, `{WILDCARD: …}`).
 *
 * The Analyzer must have the `"position"` and `"frequency"` features enabled,
 * otherwise `PHRASE()` finds nothing. When `$analyzer` is omitted, the Analyzer
 * of a wrapping {@see analyzer()} call applies (default `"identity"`).
 *
 * Example AQL usage:
 * ```aql
 * PHRASE(doc.text, "quick fox", "text_en")
 * PHRASE(doc.text, ["ipsum", 2, "amet"], "text_en")              // 2 wildcard tokens between
 * PHRASE(doc.text, ["lorem", {STARTS_WITH: ["ips"]}], "text_en") // prefix object token
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\phrase;
 *
 * echo phrase( 'doc.text' , 'quick fox' , 'text_en' ) ;
 * // 'PHRASE(doc.text,"quick fox","text_en")'
 *
 * echo phrase( 'doc.text' , [ 'ipsum' , 2 , 'amet' ] , 'text_en' ) ;
 * // 'PHRASE(doc.text,["ipsum",2,"amet"],"text_en")'
 *
 * echo phrase( 'doc.text' , [ 'lorem' , [ 'STARTS_WITH' => [ 'ips' ] ] ] , 'text_en' ) ;
 * // 'PHRASE(doc.text,["lorem",{"STARTS_WITH":["ips"]}],"text_en")'
 * ```
 *
 * @param string       $path     Attribute path expression to test (kept raw).
 * @param string|array $phrase   The phrase: a plain string, or the AQL array form
 *                               (tokens, skipTokens numbers, object tokens).
 * @param string|null  $analyzer Optional Analyzer name (emitted as a quoted string literal).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#phrase
 * @see analyzer()
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function phrase( string $path , string|array $phrase , ?string $analyzer = null ) : string
{
    $args = [ $path , json_encode( $phrase ) ] ;

    if ( $analyzer !== null )
    {
        $args[] = json_encode( $analyzer ) ;
    }

    return func( SearchFunction::PHRASE , $args ) ;
}
