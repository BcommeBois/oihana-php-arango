<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Recursively merge multiple documents into a single document.
 *
 * Wraps the ArangoDB AQL function `MERGE_RECURSIVE(document1, … documentN)`. Unlike
 * {@see merge()}, sub-documents with the same key are merged recursively rather than
 * replaced wholesale.
 *
 * Example AQL usage:
 * ```aql
 * MERGE_RECURSIVE({a: {b: 1}}, {a: {c: 2}})   // returns {a: {b: 1, c: 2}}
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\mergeRecursive;
 *
 * $expr = mergeRecursive(['doc1', 'doc2']);
 * // Produces: 'MERGE_RECURSIVE(doc1,doc2)'
 * ```
 *
 * @param string|array|null $documents Multiple documents to merge (at least 2 required).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#merge_recursive
 * @see merge()
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function mergeRecursive( string|array|null $documents ) : string
{
    return func( DocumentFunction::MERGE_RECURSIVE , $documents ) ;
}
