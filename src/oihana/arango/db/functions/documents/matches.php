<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Test whether a document matches one of the given example documents.
 *
 * Wraps the ArangoDB AQL function `MATCHES(document, examples, returnIndex)`. The
 * `examples` argument is emitted as a JSON literal when given as a PHP array; a string
 * is passed through as a raw AQL expression. With `$returnIndex = true`, the function
 * returns the (zero-based) index of the first matching example instead of a boolean.
 *
 * Example AQL usage:
 * ```aql
 * MATCHES(doc, {age: 30})                       // true if doc.age == 30
 * MATCHES(doc, [{age: 30}, {age: 40}], true)    // index of the first matching example, or -1
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\matches;
 *
 * $expr = matches('doc', ['age' => 30]);
 * // Produces: 'MATCHES(doc,{"age":30})'
 *
 * $expr = matches('doc', [['age' => 30], ['age' => 40]], true);
 * // Produces: 'MATCHES(doc,[{"age":30},{"age":40}],true)'
 * ```
 *
 * @param string       $document    The document variable or expression to test.
 * @param string|array $examples    A single example or a list of examples (array literal or AQL expression).
 * @param bool|null    $returnIndex Whether to return the matching example index instead of a boolean.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#matches
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function matches( string $document , string|array $examples , ?bool $returnIndex = null ) : string
{
    $args = [ $document , is_array( $examples ) ? json_encode( $examples ) : $examples ] ;
    if ( $returnIndex !== null )
    {
        $args[] = $returnIndex ;
    }
    return func( DocumentFunction::MATCHES , $args ) ;
}
