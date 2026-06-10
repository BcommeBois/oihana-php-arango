<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Check whether a string starts with a prefix (or with one of several prefixes).
 *
 * This helper wraps the ArangoDB AQL function `STARTS_WITH(text, prefix)` which
 * checks if the given string starts with the specified prefix. The comparison
 * is case-sensitive.
 *
 * Inside a `SEARCH` operation the ArangoSearch form
 * `STARTS_WITH(path, prefixes, minMatchCount)` is also supported: pass an
 * **array** of prefixes (emitted with `json_encode`, so plain strings are
 * quoted) and an optional minimum number of prefixes that must match.
 * A **string** prefix is kept raw, as before (callers quote it themselves).
 *
 * Example AQL usage:
 * ```aql
 * STARTS_WITH("hello world", "hello")       // returns true
 * STARTS_WITH("hello world", "world")       // returns false
 * STARTS_WITH("Hello world", "hello")       // returns false (case-sensitive)
 * STARTS_WITH(doc.text, ["lor", "ips"], 1)  // SEARCH form: at least 1 prefix matches
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\startsWith;
 *
 * $expr = startsWith('doc.name', '"John"');
 * // Produces: 'STARTS_WITH(doc.name,"John")'
 *
 * $expr = startsWith('doc.text', [ 'lor' , 'ips' ] , 1 );
 * // Produces: 'STARTS_WITH(doc.text,["lor","ips"],1)'
 * ```
 *
 * @param string       $value         String expression to check.
 * @param string|array $prefix        Prefix to test for: a raw string expression (kept as-is),
 *                                    or an array of prefix strings (JSON-encoded).
 * @param int|null     $minMatchCount Optional minimum number of prefixes that must match
 *                                    (ArangoSearch `SEARCH` form, used with an array of prefixes).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#starts_with
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#starts_with
 * @see contains() For checking if string contains substring.
 * @see like() For pattern matching.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function startsWith( string $value , string|array $prefix , ?int $minMatchCount = null ): string
{
    $args = [ $value , is_array( $prefix ) ? json_encode( $prefix ) : $prefix ] ;

    if ( $minMatchCount !== null )
    {
        $args[] = $minMatchCount ;
    }

    return func(StringFunction::STARTS_WITH , $args ) ;
}

