<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Match documents within a (Damerau-)Levenshtein distance of a target string.
 *
 * Wraps the ArangoDB AQL function
 * `LEVENSHTEIN_MATCH(path, target, distance, transpositions, maxTerms, prefix)`.
 * By default a **Damerau**-Levenshtein distance is computed (transpositions count
 * as one operation); pass `transpositions: false` for a pure Levenshtein distance.
 * The maximum `distance` is `4` without transpositions and `3` with them.
 *
 * AQL arguments are positional: when a later option is provided, the helper
 * fills the earlier omitted ones with the **official server defaults**
 * (`transpositions = true`, `maxTerms = 64`) so callers never need to know them.
 * Trailing omitted options are not emitted at all. When using `$prefix`, the
 * prefix must be **removed from `$target`** (the distance is computed on the
 * remainders — see the official documentation).
 *
 * Example AQL usage:
 * ```aql
 * LEVENSHTEIN_MATCH(doc.text, "quikc", 2, false)        // pure Levenshtein, matches "quick"
 * LEVENSHTEIN_MATCH(doc.text, "kc", 1, false, 64, "qui") // prefix search
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\levenshteinMatch;
 *
 * echo levenshteinMatch( 'doc.text' , 'quikc' , 1 ) ;
 * // 'LEVENSHTEIN_MATCH(doc.text,"quikc",1)'
 *
 * echo levenshteinMatch( 'doc.text' , 'quikc' , 2 , false ) ;
 * // 'LEVENSHTEIN_MATCH(doc.text,"quikc",2,false)'
 *
 * echo levenshteinMatch( 'doc.text' , 'kc' , 1 , false , prefix: 'qui' ) ;
 * // 'LEVENSHTEIN_MATCH(doc.text,"kc",1,false,64,"qui")'
 * ```
 *
 * @param string      $path           Attribute path expression to test (kept raw).
 * @param string      $target         String to compare against (emitted as a quoted string literal).
 * @param int         $distance       Maximum edit distance: `0…4` if `$transpositions` is `false`, `0…3` otherwise.
 * @param bool|null   $transpositions Optional — `false` for a pure Levenshtein distance (server default `true`).
 * @param int|null    $maxTerms       Optional — number of most relevant terms to consider, `0` for all (server default `64`).
 * @param string|null $prefix         Optional — known common prefix (emitted as a quoted string literal); improves performance.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#levenshtein_match
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function levenshteinMatch
(
    string  $path ,
    string  $target ,
    int     $distance ,
    ?bool   $transpositions = null ,
    ?int    $maxTerms       = null ,
    ?string $prefix         = null
)
: string
{
    $args = [ $path , json_encode( $target ) , $distance ] ;

    if ( $prefix !== null )
    {
        $args[] = json_encode( $transpositions ?? true ) ;
        $args[] = $maxTerms ?? 64 ;
        $args[] = json_encode( $prefix ) ;
    }
    elseif ( $maxTerms !== null )
    {
        $args[] = json_encode( $transpositions ?? true ) ;
        $args[] = $maxTerms ;
    }
    elseif ( $transpositions !== null )
    {
        $args[] = json_encode( $transpositions ) ;
    }

    return func( SearchFunction::LEVENSHTEIN_MATCH , $args ) ;
}
