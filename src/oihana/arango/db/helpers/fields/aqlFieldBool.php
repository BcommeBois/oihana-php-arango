<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use function oihana\arango\db\functions\toBool;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression that converts a document field to boolean.
 *
 * This helper builds a snippet suitable for a `RETURN { ... }` block in AQL.
 * It references a field in the given document (or alias) and wraps it with the
 * `TO_BOOL()` function, ensuring the value is interpreted as a boolean in AQL.
 *
 * If `$keyName` is not provided, the `$key` parameter is used as both the
 * resulting key in the object and the field name in the document.
 *
 * Example usage:
 * ```aql
 * // PHP call
 * aqlFieldBool('isActive');
 *
 * // Generates
 * isActive: TO_BOOL(doc.isActive)
 * ```
 *
 * @param string      $key     The key to use in the resulting AQL object (e.g. `"isActive"`).
 * @param string      $doc     The document alias or variable name to reference (default: `AQL::DOC`).
 * @param string|null $keyName Optional field name in the document; if omitted, `$key` is used.
 *
 * @return string AQL key/value expression, e.g. `"isActive: TO_BOOL(doc.isActive)"`.
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldBool( string $key , string $doc = AQL::DOC , ?string $keyName = null ): string
{
    return keyValue( $key , toBool( key( $keyName ?? $key , $doc ) ) ) ;
}