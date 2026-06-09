<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the attribute keys of a document.
 *
 * Wraps the ArangoDB AQL function `ATTRIBUTES(document, removeSystemAttrs, sort)`
 * (also aliased as `KEYS()`).
 *
 * Example AQL usage:
 * ```aql
 * ATTRIBUTES({b: 2, _key: "x", a: 1})              // returns ["b", "_key", "a"]
 * ATTRIBUTES({b: 2, _key: "x", a: 1}, true)        // returns ["b", "a"]
 * ATTRIBUTES({b: 2, _key: "x", a: 1}, true, true)  // returns ["a", "b"]
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\attributes;
 *
 * $expr = attributes('doc');
 * // Produces: 'ATTRIBUTES(doc)'
 *
 * $expr = attributes('doc', true, true);
 * // Produces: 'ATTRIBUTES(doc,true,true)'
 * ```
 *
 * @param string    $document          The document variable or expression.
 * @param bool|null $removeSystemAttrs Whether to omit system attributes (`_id`, `_key`, `_rev`, ...).
 * @param bool|null $sort              Whether to sort the keys alphabetically.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#attributes
 * @see keys()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function attributes( string $document , ?bool $removeSystemAttrs = null , ?bool $sort = null ) : string
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
    return func( DocumentFunction::ATTRIBUTES , $args ) ;
}
