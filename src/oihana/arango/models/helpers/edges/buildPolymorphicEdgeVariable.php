<?php

namespace oihana\arango\models\helpers\edges;

use Exception;
use ReflectionException;
use UnexpectedValueException;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\enums\Arango;
use oihana\arango\db\enums\AQL;

use function oihana\arango\db\functions\arrays\append;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operators\equal;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\key;

/**
 * Builds a single AQL 'LET' subquery for a *polymorphic* edge — an edge whose
 * traversed collection is chosen at query time from a discriminator field of the
 * start vertex (the parent document).
 *
 * AQL forbids a computed collection in `FOR … IN <dir> … <collection>`, so a
 * polymorphic edge is expressed as an **`APPEND` of guarded static traversals**:
 * one regular edge sub-query per `Arango::MAP` entry, each guarded by an equality
 * on the discriminator so only the matching branch yields rows. The resulting
 * `LET` therefore always holds an **array** — exactly like a regular edge — and
 * the projection layer ({@see \oihana\arango\db\helpers\fields\aqlFieldObject()})
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
 * Each branch is delegated to {@see buildEdgeSubquery()} (which already returns a
 * parenthesized traversal), so the whole edge machinery — direction, depth, path
 * metadata, skinning, nested edges / joins — applies per branch. A branch may
 * therefore declare its own `AQL::DIRECTION`, `AQL::MAX_DEPTH`, etc.
 *
 * @param string|null             $name        The edge field name — also the default `LET` variable name.
 * @param array                   $definition  The polymorphic edge definition. Keys:
 * - `Arango::DISCRIMINATOR` (string)  Start-vertex field path deciding the branch (required).
 * - `Arango::MAP` (array)             Non-empty `type => edge-definition` table (required).
 * - `Arango::UNIQUE` (string|null)    Optional `LET` variable name, overrides `$name`.
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
    if( empty( $name ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the name of the edge variable not must be null or empty.' ) ;
    }

    $map = $definition[ Arango::MAP ] ?? null ;
    if( !is_array( $map ) || $map === [] )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, a polymorphic edge requires a non-empty Arango::MAP table.' ) ;
    }

    $discriminator = $definition[ Arango::DISCRIMINATOR ] ?? null ;
    if( !is_string( $discriminator ) || $discriminator === '' )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, a polymorphic edge requires a non-empty Arango::DISCRIMINATOR field.' ) ;
    }

    $varName = $definition[ Arango::UNIQUE ] ?? $name ;

    $discriminatorRef = key( $discriminator , $startVertex ) ; // e.g. doc.kind

    $subqueries = [] ;

    foreach( $map as $type => $branch )
    {
        if( !is_array( $branch ) )
        {
            throw new UnexpectedValueException
            (
                __FUNCTION__ . ' failed, the Arango::MAP branch "' . $type . '" must be an edge definition array.'
            ) ;
        }

        // Guard: only the branch whose discriminator value matches yields rows.
        // buildEdgeSubquery already returns a parenthesized traversal.
        $guard = equal( $discriminatorRef , betweenDoubleQuotes( (string) $type ) ) ;

        $subqueries[] = buildEdgeSubquery( $name , $branch , $startVertex , $container , $init , [ $guard ] ) ;
    }

    // Concatenate the branch arrays: only the matching branch is non-empty, so
    // the projection's FIRST() (Filter::EDGE) or the whole array (Filter::EDGES)
    // resolves to the right vertex(es).
    $combined = array_shift( $subqueries ) ;
    foreach( $subqueries as $subquery )
    {
        $combined = append( $combined , $subquery ) ;
    }

    return aqlLet( $varName , $combined ) ;
}
