<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Return the attribute values of a document.
 *
 * Wraps the ArangoDB AQL function `VALUES(document, removeSystemAttrs)`.
 *
 * Example AQL usage:
 * ```aql
 * VALUES({a: 1, b: 2})                  // returns [1, 2]
 * VALUES({a: 1, _key: "x"}, true)       // returns [1]
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\values;
 *
 * $expr = values('doc');
 * // Produces: 'VALUES(doc)'
 *
 * $expr = values('doc', true);
 * // Produces: 'VALUES(doc,true)'
 * ```
 *
 * @param string    $document          The document variable or expression.
 * @param bool|null $removeSystemAttrs Whether to omit system attributes (`_id`, `_key`, `_rev`, ...).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#values
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function values( string $document , ?bool $removeSystemAttrs = null ) : string
{
    $args = [ $document ] ;
    if ( $removeSystemAttrs !== null )
    {
        $args[] = $removeSystemAttrs ;
    }
    return func( DocumentFunction::VALUES , $args ) ;
}
