<?php

namespace oihana\arango\models\helpers\edges;

use Exception;
use ReflectionException;
use UnexpectedValueException;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;

use function oihana\arango\models\helpers\buildPolymorphicRelationVariable;

/**
 * Builds a single AQL 'LET' subquery for a *polymorphic* edge — an edge whose
 * traversed collection is chosen at query time from a discriminator field of the
 * start vertex (the parent document).
 *
 * The whole branch machinery (guarding, per-branch permission gating, the
 * `FALLBACK` branch, the `APPEND` combine) lives in the shared
 * {@see buildPolymorphicRelationVariable()}; this function only supplies the
 * per-branch builder, which delegates to {@see buildEdgeSubquery()} (already a
 * parenthesized traversal). Each branch is a full edge definition, so it may
 * declare its own `AQL::DIRECTION`, `AQL::MAX_DEPTH`, etc.
 *
 * The resulting `LET` always holds an **array** — exactly like a regular edge —
 * so the projection layer ({@see \oihana\arango\db\helpers\fields\aqlFieldObject()})
 * unwraps it with `FIRST()` for a `Filter::EDGE` or keeps the whole array for a
 * `Filter::EDGES`.
 *
 * Example output (two branches):
 * ```
 * LET rel = APPEND(
 *   ( FOR vertex, edge IN OUTBOUND doc warehouse_edges
 *       FILTER doc.kind == "warehouse"
 *       RETURN { _key: vertex._key, name: vertex.name } ) ,
 *   ( FOR vertex, edge IN OUTBOUND doc company_edges
 *       FILTER doc.kind == "company"
 *       RETURN { _key: vertex._key, name: vertex.name } )
 * )
 * ```
 *
 * @param string|null             $name        The edge field name — also the default `LET` variable name.
 * @param array                   $definition  The polymorphic edge definition. Keys:
 * - `Arango::DISCRIMINATOR` (string)  Start-vertex field path deciding the branch (required).
 * - `Arango::MAP` (array)             Non-empty `type => edge-definition` table (required).
 * - `Arango::UNIQUE` (string|null)    Optional `LET` variable name, overrides `$name`.
 * - `Arango::FALLBACK` (array|null)   Edge definition for unmatched discriminator values (`null` = none).
 * @param string                  $startVertex The AQL variable name of the start vertex.
 * @param ContainerInterface|null $container   Optional DI container used to resolve branch models.
 * @param array                   $init        Optional associative array used for variable initialization.
 *
 * @return string The complete AQL 'LET' statement.
 *
 * @throws Exception                   If a branch traversal cannot be built.
 * @throws ContainerExceptionInterface If a branch model cannot be resolved from the container.
 * @throws NotFoundExceptionInterface  If a branch model cannot be found in the container.
 * @throws ReflectionException
 * @throws UnexpectedValueException    If $name is empty, or the definition lacks a non-empty
 *                                     `Arango::MAP` / `Arango::DISCRIMINATOR`.
 *
 * @package oihana\arango\models\helpers\edges
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function buildPolymorphicEdgeVariable
(
    ?string             $name        ,
    array               $definition  = [] ,
    string              $startVertex = AQL::DOC ,
    ?ContainerInterface $container   = null ,
    array               $init        = [] ,
)
: string
{
    // buildEdgeSubquery already returns a parenthesized traversal.
    $buildBranch = fn( array $branch , string $guard ) : string
        => buildEdgeSubquery( $name , $branch , $startVertex , $container , $init , [ $guard ] ) ;

    return buildPolymorphicRelationVariable( $name , $definition , $startVertex , $init , $buildBranch ) ;
}
