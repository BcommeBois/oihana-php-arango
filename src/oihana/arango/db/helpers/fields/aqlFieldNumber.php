<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\toNumber;
use function oihana\arango\db\operators\notEqual;
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
 * Abstaining (`Field::NULLABLE`):
 *
 * `TO_NUMBER()` converts even when there is nothing to convert: a document that says nothing
 * about the attribute comes back with `0`, indistinguishable from one that really stores `0`
 * — « it is free » and « we have no price » collapse into one value. An opt-in
 * `Field::NULLABLE` guards the cast behind the presence of the attribute, and `Field::ELSE`
 * picks what is said instead (default `null`):
 *
 * ```php
 * aqlFieldNumber( 'price' , 'doc' , null , [ Field::NULLABLE => true ] ) ;
 * // price:doc.price != null ? TO_NUMBER(doc.price) : null
 * ```
 *
 * The test is `!= null`, **not** `IS_NUMBER()`: `TO_NUMBER()` exists precisely to accept what
 * is not a number — a document storing `"42"` counts as `42` today — so a type test would
 * make all of them abstain. The question asked is only « is the attribute there? ». Without
 * the marker the emitted AQL is unchanged, byte for byte.
 *
 * @param string      $key     The logical key to use in the AQL return object.
 * @param string      $doc     The document variable or alias (default: `doc` / `AQL::DOC`).
 * @param string|null $keyName Optional property name in the document if different from `$key`.
 * @param array       $options The field definition, read for the optional guard (`Field::NULLABLE`,
 *                             `Field::ELSE`).
 *
 * @return string AQL key/value snippet for numeric conversion (e.g. `"age: TO_NUMBER(doc.age)"`).
 *
 * @throws UnsupportedOperationException If the guard descriptor is malformed.
 * @throws ValidationException           If an else attribute name is unsafe.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldNumber
(
    string  $key ,
    string  $doc     = AQL::DOC ,
    ?string $keyName = null ,
    array   $options = []
)
: string
{
    $source = key( $keyName ?? $key , $doc ) ;
    return keyValue( $key , guardProjection( toNumber( $source ) , $options , $doc , $source , notEqual( $source , AQL::NULL ) ) ) ;
}