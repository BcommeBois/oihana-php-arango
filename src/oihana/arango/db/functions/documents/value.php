<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Extract a value from a document at the specified path.
 *
 * This helper wraps the ArangoDB AQL function `VALUE(document, path)` which extracts
 * a value from a document using a path array. The path can contain strings (object keys)
 * and integers (array indices) to navigate through nested structures.
 *
 * Example AQL usage:
 * ```aql
 * VALUE(doc, ["author", "name"])        // extracts doc.author.name
 * VALUE(doc, ["tags", 0])               // extracts first element of doc.tags array
 * VALUE(doc, ["metadata", "version"])   // extracts doc.metadata.version
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\value;
 *
 * $expr = value('doc', ['author', 'name']);
 * // Produces: 'VALUE(doc, ["author","name"])'
 *
 * $expr = value('doc', ['tags', 0]);
 * // Produces: 'VALUE(doc, ["tags",0])'
 * ```
 *
 * @param string $document The document variable or expression to extract value from.
 * @param array $path An array of strings and numbers describing the attribute path.
 *                    Use strings for object keys and integers for array indices.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#value
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function value( string $document , array $path ) : string
{
    return func( DocumentFunction::VALUE , [ $document , json_encode( $path ) ] ) ;
}

