<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\toBool;
use function oihana\arango\db\operators\notEqual;
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
 * Abstaining (`Field::NULLABLE`):
 *
 * `TO_BOOL()` answers even when nothing was asked: a document that says nothing about the
 * attribute comes back with `false`, indistinguishable from one that stores `false`. An
 * opt-in `Field::NULLABLE` guards the cast behind the presence of the attribute, and
 * `Field::ELSE` picks what is said instead (default `null`):
 *
 * ```php
 * aqlFieldBool( 'active' , 'doc' , null , [ Field::NULLABLE => true ] ) ;
 * // active:doc.active != null ? TO_BOOL(doc.active) : null
 * ```
 *
 * The test is `!= null`, **not** `IS_BOOL()`: `TO_BOOL()` exists precisely to accept what is
 * not a boolean — a document storing `1` or `"yes"` counts as `true` today — so a type test
 * would make all of them abstain. The question asked is only « is the attribute there? ».
 * Without the marker the emitted AQL is unchanged, byte for byte.
 *
 * @param string      $key     The key to use in the resulting AQL object (e.g. `"isActive"`).
 * @param string      $doc     The document alias or variable name to reference (default: `AQL::DOC`).
 * @param string|null $keyName Optional field name in the document; if omitted, `$key` is used.
 * @param array       $options The field definition, read for the optional guard (`Field::NULLABLE`,
 *                             `Field::ELSE`).
 *
 * @return string AQL key/value expression, e.g. `"isActive: TO_BOOL(doc.isActive)"`.
 *
 * @throws UnsupportedOperationException If the guard descriptor is malformed.
 * @throws ValidationException           If an else attribute name is unsafe.
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldBool( string $key , string $doc = AQL::DOC , ?string $keyName = null , array $options = [] ): string
{
    $source = key( $keyName ?? $key , $doc ) ;
    return keyValue( $key , guardProjection( toBool( $source ) , $options , $doc , $source , notEqual( $source , AQL::NULL ) ) ) ;
}