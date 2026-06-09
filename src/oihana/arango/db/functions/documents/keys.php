<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the attribute keys of a document (an alias of {@see attributes()} / `ATTRIBUTES()`).
 *
 * Wraps the ArangoDB AQL function `KEYS(document, removeSystemAttrs, sort)`.
 *
 * Example AQL usage:
 * ```aql
 * KEYS({b: 2, a: 1})         // returns ["b", "a"]
 * KEYS({b: 2, a: 1}, false, true)   // returns ["a", "b"] (sorted)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\keys;
 *
 * $expr = keys('doc');
 * // Produces: 'KEYS(doc)'
 *
 * $expr = keys('doc', true, true);
 * // Produces: 'KEYS(doc,true,true)'
 * ```
 *
 * @param string    $document          The document variable or expression.
 * @param bool|null $removeSystemAttrs Whether to omit system attributes (`_id`, `_key`, `_rev`, ...).
 * @param bool|null $sort              Whether to sort the keys alphabetically.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#keys
 * @see attributes()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function keys( string $document , ?bool $removeSystemAttrs = null , ?bool $sort = null ) : string
{
    $args = [ $document ] ;
    if ( $removeSystemAttrs !== null || $sort !== null )
    {
        $args[] = $removeSystemAttrs ?? false ;
    }
    if ( $sort !== null )
    {
        $args[] = $sort ;
    }
    return func( DocumentFunction::KEYS , $args ) ;
}
