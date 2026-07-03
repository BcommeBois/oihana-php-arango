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
use function oihana\arango\models\helpers\authorizeRelationFields;
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
 * The wrapped reference may also carry **its own relations**. The sub-fields can
 * include the usual relation markers (`Filter::EDGE` / `Filter::EDGES` /
 * `Filter::EDGES_COUNT` for edges, `Filter::JOIN` / `Filter::JOINS` for joins) and
 * the field declares a companion `Field::EDGES` / `Field::JOINS` map of sub-relations
 * that start **from the wrapped vertex** — exactly the same shape as a top-level
 * projection (fields markers beside an edges/joins registry). The backing `LET`
 * variables are emitted upstream by `buildVariables()` with `$ref` as the traversal
 * root, so the related entities nest **inside** the wrapped object (e.g.
 * `subject.worksFor`) in a single query. `Field::RAW` is mutually exclusive with
 * `Field::EDGES` / `Field::JOINS` (a verbatim reference has no projected object to
 * nest into).
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
 * // Wrap the vertex AND nest one of its relations under the wrapped key.
 * // The sub-edge declares the cardinality marker in Field::FIELDS and the
 * // traversal definition in Field::EDGES (here reached INBOUND).
 * aqlFieldWrap( 'subject', 'v',
 * [
 *     Field::FIELDS =>
 *     [
 *         'id'       => Filter::DEFAULT ,
 *         'name'     => Filter::DEFAULT ,
 *         'worksFor' => [ Field::FILTER => Filter::EDGE , Field::UNIQUE => 'worksFor_e1' ] ,
 *     ] ,
 *     Field::EDGES =>
 *     [
 *         'worksFor' => [ AQL::MODEL => OrgHasMember::class , AQL::DIRECTION => Traversal::INBOUND ] ,
 *     ] ,
 * ]);
 * // Produces (the worksFor_e1 LET being emitted upstream against `v`):
 * // "subject: { id: v.id, name: v.name, worksFor: (IS_OBJECT(worksFor_e1) ? … ) }"
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
 * - Field::FIELDS => array of sub-fields projected against `$ref` (may include relation markers)
 * - Field::EDGES  => array of sub-traversal definitions starting from `$ref`, nested under `$key`
 * - Field::JOINS  => array of sub-join definitions resolved from `$ref`, nested under `$key`
 * - Field::RAW    => bool, embed the whole reference when no sub-fields are given (excludes Field::EDGES / Field::JOINS)
 * @param ContainerInterface|null $container The optional DI Container reference.
 * @param array $init Optional associative array definition.
 *
 * @return string AQL key/value expression wrapping the reference.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws UnsupportedOperationException If neither `Field::FIELDS` nor `Field::RAW => true` is provided,
 *                                       or if `Field::RAW => true` is combined with `Field::EDGES` / `Field::JOINS`.
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
    $edges  = $options[ Field::EDGES  ] ?? null;
    $joins  = $options[ Field::JOINS  ] ?? null;
    $raw    = ( $options[ Field::RAW ] ?? false ) === true ;

    // Field::RAW embeds the whole reference verbatim (`key: ref`) — there is no projected object to graft a relation onto.
    // Declaring sub-edges/sub-joins alongside RAW is therefore contradictory and rejected explicitly rather than silently dropping one of the two intents.
    if ( $raw && ( ( is_array( $edges ) && count( $edges ) > 0 ) || ( is_array( $joins ) && count( $joins ) > 0 ) ) )
    {
        throw new UnsupportedOperationException
        (
            __FUNCTION__ . " failed, Filter::WRAP on the field '" . $key . "' cannot combine Field::RAW => true with Field::EDGES or Field::JOINS : the raw reference is embedded as-is, there is no projected object to nest the relations into."
        ) ;
    }

    if ( is_array( $fields ) && count( $fields ) > 0 )
    {
        // Definition-level gating: the `LET` walk (the WRAP branch of buildVariables)
        // and this projection walk both read the same normalized definition — the same
        // purge applied on each side keeps them symmetric (the helper is idempotent).
        $fields = authorizeRelationFields( $fields , $edges , $joins , $init ) ;

        // The wrapped sub-fields may include relation markers (Filter::EDGE / EDGES / EDGES_COUNT / JOIN / JOINS)
        // whose backing `LET` variables were emitted by buildVariables() with the wrapped reference as traversal root ;
        // aqlFields() projects them as `relation: <letVariable>` here, exactly as a top-level projection does.
        return keyValue( $key , aqlDocument( aqlFields( $fields , $ref , $container , $init ) ) ) ;
    }

    if ( $raw )
    {
        return keyValue( $key , $ref ) ;
    }

    throw new UnsupportedOperationException
    (
        __FUNCTION__ . " failed, Filter::WRAP on the field '" . $key . "' requires Field::FIELDS, or Field::RAW => true to embed the whole reference."
    ) ;
}
