<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use function oihana\arango\db\functions\toNumber;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression for a numeric field.
 *
 * This helper constructs an expression suitable for inclusion in a `RETURN { ... }` block,
 * converting a document property to a numeric value using `TO_NUMBER()`.
 * It ensures that non-numeric values are safely handled by AQL.
 *
 * Behavior:
 * - `$key` becomes the key in the resulting AQL object.
 * - `$doc` is the document alias or variable.
 * - `$keyName` optionally specifies a different property name in the document; defaults to `$key`.
 *
 * Example usage:
 * ```php
 * // PHP call
 * aqlFieldInt('age');
 * // Generates: age: TO_NUMBER(doc.age)
 *
 * aqlFieldInt('id', 'u', 'identifier');
 * // Generates: id: TO_NUMBER(u.identifier)
 * ```
 *
 * @param string      $key     The logical key to use in the AQL return object.
 * @param string      $doc     The document variable or alias (default: `doc` / `AQL::DOC`).
 * @param string|null $keyName Optional property name in the document if different from `$key`.
 *
 * @return string AQL key/value snippet for numeric conversion (e.g. `"age: TO_NUMBER(doc.age)"`).
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldNumber
(
    string  $key ,
    string  $doc     = AQL::DOC ,
    ?string $keyName = null
)
: string
{
    return keyValue( $key , toNumber( key( $keyName ?? $key , $doc ) ) ) ;
}