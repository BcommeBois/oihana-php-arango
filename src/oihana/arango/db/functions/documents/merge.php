<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Merge multiple documents into a single document.
 *
 * This helper wraps the ArangoDB AQL function `MERGE(document1, document2, ... documentN)`
 * which merges multiple documents into a single document. Later documents override
 * attributes from earlier documents if they have the same key.
 *
 * Example AQL usage:
 * ```aql
 * MERGE(doc1, doc2)             // merges doc1 and doc2
 * MERGE(doc1, doc2, doc3)       // merges three documents
 * MERGE({a: 1}, {b: 2})         // returns {a: 1, b: 2}
 * MERGE({a: 1}, {a: 2})         // returns {a: 2} (later overrides)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\merge;
 *
 * $expr = merge(['doc1', 'doc2']);
 * // Produces: 'MERGE(doc1, doc2)'
 *
 * $expr = merge('doc1, doc2, doc3');
 * // Produces: 'MERGE(doc1, doc2, doc3)'
 * ```
 *
 * @param string|array|null $documents Multiple documents to merge (at least 2 required).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#merge
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function merge( string|array|null $documents ) : string
{
    return func( DocumentFunction::MERGE , $documents ) ;
}

