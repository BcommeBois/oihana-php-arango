<?php

namespace oihana\arango\db\helpers\fields;

use oihana\exceptions\ValidationException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression that wraps the **current reference**
 * itself under a named key.
 *
 * Unlike {@see aqlFieldDocument()} — which nests a **sub-attribute** of the
 * reference (`ref.key.subfield`) — this helper projects the sub-fields directly
 * against `$ref` (`ref.subfield`), so the whole reference is embedded inside a
 * new object under `$key`. It is the symmetric counterpart of
 * {@see \oihana\arango\enums\Field::SCOPE} : inside an edge traversal it lets a
 * definition hoist the **traversal vertex** (the related entity) under a named
 * key — e.g. `subject` — alongside the edge metadata, instead of flattening the
 * vertex fields at the root.
 *
 * - With `Field::FIELDS` : projects the listed sub-fields against `$ref` and
 *   wraps them in a `{ ... }` object under `$key`.
 * - Without `Field::FIELDS` : a field whitelist is **required by default**. Pass
 *   `Field::RAW => true` to deliberately embed the **whole** reference as-is
 *   (`key: ref`) — every attribute of the vertex/edge, no projection.
 *
 * Example usage:
 * ```php
 * // Wrap the traversal vertex under "subject", with a projection
 * aqlFieldWrap( 'subject', 'v',
 * [
 *     Field::FIELDS =>
 *     [
 *         'id'        => Filter::DEFAULT ,
 *         'givenName' => Filter::DEFAULT ,
 *     ]
 * ]);
 * // Produces: "subject: { id: v.id, givenName: v.givenName }"
 *
 * // Wrap the whole vertex as-is (opt-in)
 * aqlFieldWrap( 'subject', 'v', [ Field::RAW => true ] );
 * // Produces: "subject: v"
 * ```
 *
 * @param string $key The output key under which the reference is wrapped.
 * @param string $ref The reference to wrap (the traversal vertex `v` by default,
 *                    or the edge `e` when the field declares `Field::SCOPE => Scope::EDGE`).
 * @param array $options Field options, typically including:
 * - Field::FIELDS => array of sub-fields projected against `$ref`
 * - Field::RAW    => bool, embed the whole reference when no sub-fields are given
 * @param ContainerInterface|null $container The optional DI Container reference.
 * @param array $init Optional associative array definition.
 *
 * @return string AQL key/value expression wrapping the reference.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws UnsupportedOperationException If neither `Field::FIELDS` nor `Field::RAW => true` is provided.
 * @throws ValidationException
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldWrap
(
    string              $key ,
    string              $ref ,
    array               $options ,
    ?ContainerInterface $container = null ,
    array               $init      = []
)
: string
{
    $fields = $options[ Field::FIELDS ] ?? null;

    if ( is_array( $fields ) && count( $fields ) > 0 )
    {
        return keyValue( $key , aqlDocument( aqlFields( $fields , $ref , $container , $init ) ) ) ;
    }

    if ( ( $options[ Field::RAW ] ?? false ) === true )
    {
        return keyValue( $key , $ref ) ;
    }

    throw new UnsupportedOperationException
    (
        __FUNCTION__ . " failed, Filter::WRAP on the field '" . $key . "' requires Field::FIELDS, or Field::RAW => true to embed the whole reference."
    ) ;
}
