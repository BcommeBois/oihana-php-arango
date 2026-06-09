<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Build a document from an array of attribute keys and an array of values.
 *
 * Wraps the ArangoDB AQL function `ZIP(keys, values)`. PHP arrays are emitted as JSON
 * array literals (`json_encode`); strings are passed through as raw AQL expressions
 * (e.g. `doc.keys`).
 *
 * Example AQL usage:
 * ```aql
 * ZIP(["a", "b"], [1, 2])   // returns {a: 1, b: 2}
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\zip;
 *
 * $expr = zip(['a', 'b'], [1, 2]);
 * // Produces: 'ZIP(["a","b"],[1,2])'
 *
 * $expr = zip('doc.keys', 'doc.values');
 * // Produces: 'ZIP(doc.keys,doc.values)'
 * ```
 *
 * @param string|array $keys   The attribute keys (array literal or AQL expression).
 * @param string|array $values The values, in the same order (array literal or AQL expression).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#zip
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function zip( string|array $keys , string|array $values ) : string
{
    $k = is_array( $keys   ) ? json_encode( $keys   ) : $keys   ;
    $v = is_array( $values ) ? json_encode( $values ) : $values ;
    return func( DocumentFunction::ZIP , [ $k , $v ] ) ;
}
