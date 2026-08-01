<?php

namespace oihana\arango\db\helpers\fields;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\models\helpers\authorizeRelationFields;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression for a DOCUMENT-type field.
 *
 * This helper handles nested document fields and can include subfields recursively.
 * - If `$options[Field::FIELDS]` is provided as an array of subfields, it generates
 * a nested `{ ... }` expression using `aqlFields()`.
 * - If no subfields are defined, it falls back to a default field expression using `aqlFieldDefault()`.
 *
 * Example usage:
 * ```php
 * // Simple document field
 * aqlFieldDocument('author', 'doc', ['name' => 'author']);
 * // Produces: "author: doc.author"
 *
 * // Document field with nested subfields
 * aqlFieldDocument( 'author', 'doc',
 * [
 *     Field::NAME   => 'author',
 *     Field::FIELDS =>
 *     [
 *       'firstName' => Filter::DEFAULT ,
 *       'lastName'  => Filter::DEFAULT ,
 *     ]
 * ]);
 * // Produces: "author: { firstName: doc.author.firstName, lastName: doc.author.lastName }"
 * ```
 *
 * Guarded projection (`Field::NULLABLE` / `Field::WHEN`):
 *
 * The rebuilt object is emitted unconditionally by default — when the source attribute
 * is missing, every line of the projection reads an attribute of a nothing, which AQL
 * resolves to `null` without error, and the key comes back dressed as an object of
 * nulls (`{ _key: null, url: 'https://base/things/' }`) instead of `null`. Two opt-in
 * markers guard it:
 *
 * - `Field::NULLABLE => true` — the intent « no source, no object », compiled to an
 *   `IS_OBJECT()` test on the source attribute;
 * - `Field::WHEN` — the general mechanism, the same condition grammar as a scalar
 *   conditional projection ({@see aqlFieldConditional()}), compiled against the
 *   **parent** reference (`$doc`, not the sub-document), so the permission gate of
 *   {@see conditionReadsDeniedField()} applies to it verbatim.
 *
 * Both compose with `&&`, and the false branch is `Field::ELSE` (default `null`):
 *
 * ```php
 * aqlFieldDocument( 'thing' , 'doc' ,
 * [
 *     Field::NULLABLE => true ,
 *     Field::FIELDS   => [ 'name' => [] ] ,
 * ]);
 * // Produces: "thing:IS_OBJECT(doc.thing) ? {name:doc.thing.name} : null"
 * ```
 *
 * Without either marker the emitted AQL is unchanged, byte for byte.
 *
 * @param string $key The key of the field in the parent document.
 * @param string $doc The document variable or reference for the field.
 * @param array $options Field options, typically including:
 * - Field::NAME     => actual key name in the document
 * - Field::FIELDS   => array of nested subfields
 * - Field::NULLABLE => bool, guard the rebuilt object behind `IS_OBJECT(<source>)`
 * - Field::WHEN     => optional condition guarding the projection (parent-scoped)
 * - Field::ELSE     => the guarded projection's false branch (default `null`)
 * @param ContainerInterface|null $container The optional DI Container reference.
 * @param array $init Optional associative array definition.
 *
 * @return string AQL key/value expression representing the document field.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws UnsupportedOperationException If a `Field::WHEN` descriptor is malformed.
 * @throws ValidationException           If a condition or else attribute name is unsafe.
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldDocument
(
    string              $key ,
    string              $doc ,
    array               $options ,
    ?ContainerInterface $container = null ,
    array               $init      = []
)
: string
{
    $name   = $options[ Field::NAME   ] ?? null;
    $fields = $options[ Field::FIELDS ] ?? null;

    // The source attribute the projection is rebuilt from (e.g. `doc.thing`). It also
    // drives the Field::NULLABLE guard below, hence the single computation.
    $source = key( $name ?? $key , $doc ) ;

    if ( is_array( $fields ) && count( $fields ) > 0 )
    {
        // Definition-level gating: the `LET` walk (the DOCUMENT branch of buildVariables)
        // and this projection walk both read the same normalized definition — the same
        // purge applied on each side keeps them symmetric (the helper is idempotent).
        $fields = authorizeRelationFields
        (
            $fields ,
            $options[ Field::EDGES ] ?? [] ,
            $options[ Field::JOINS ] ?? [] ,
            $init
        ) ;

        $value = aqlDocument( aqlFields( $fields , $source , $container , $init ) ) ;
    }
    else
    {
        // No sub-field whitelist: the sub-document is embedded as-is (`key: doc.key`).
        // A guard still applies — an opt-in Field::NULLABLE then means « only when the
        // source really is an object », and a Field::WHEN is honoured rather than
        // silently dropped.
        $value = $source ;
    }

    return keyValue( $key , guardProjection( $value , $options , $doc , $source ) ) ;
}