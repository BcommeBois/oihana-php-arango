<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\SearchFunction;
use function oihana\core\strings\func;

/**
 * Match documents where an attribute is present (optionally of a given type).
 *
 * Wraps the ArangoDB AQL function `EXISTS(path[, type[, analyzer]])`:
 *
 * - `exists('doc.text')` — the attribute is present;
 * - `exists('doc.text', 'string')` — present **and** of the given data type
 *   (`"null"`, `"bool"`/`"boolean"`, `"numeric"`, `"type"`, `"string"`,
 *   `"analyzer"`, `"nested"`);
 * - `exists('doc.text', analyzer: 'text_en')` — present **and** indexed by the
 *   given Analyzer; the `"analyzer"` type literal is filled in automatically.
 *
 * With `arangosearch` Views, `EXISTS()` only matches values if the
 * **storeValues** link property is set to `"id"` (default `"none"`).
 *
 * Example AQL usage:
 * ```aql
 * EXISTS(doc.text)
 * EXISTS(doc.text, "string")
 * EXISTS(doc.text, "analyzer", "text_en")
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\exists;
 *
 * echo exists( 'doc.text' ) ;                       // 'EXISTS(doc.text)'
 * echo exists( 'doc.text' , 'string' ) ;            // 'EXISTS(doc.text,"string")'
 * echo exists( 'doc.text' , analyzer: 'text_en' ) ; // 'EXISTS(doc.text,"analyzer","text_en")'
 * ```
 *
 * @param string      $path     Attribute path expression to test (kept raw).
 * @param string|null $type     Optional data type to test for (emitted as a quoted string literal).
 *                              Defaults to `"analyzer"` when `$analyzer` is provided.
 * @param string|null $analyzer Optional Analyzer name (emitted as a quoted string literal).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#exists
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function exists( string $path , ?string $type = null , ?string $analyzer = null ) : string
{
    $args = [ $path ] ;

    if ( $analyzer !== null )
    {
        $args[] = json_encode( $type ?? 'analyzer' ) ;
        $args[] = json_encode( $analyzer ) ;
    }
    elseif ( $type !== null )
    {
        $args[] = json_encode( $type ) ;
    }

    return func( SearchFunction::EXISTS , $args ) ;
}
