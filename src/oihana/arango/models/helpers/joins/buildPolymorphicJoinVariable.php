<?php

namespace oihana\arango\models\helpers\joins;

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
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\key;

/**
 * Builds a single AQL 'LET' subquery for a *polymorphic* join — a join whose
 * target collection is chosen at query time from a discriminator field of the
 * parent document.
 *
 * AQL forbids a computed collection in `FOR … IN …`, so a polymorphic join is
 * expressed as an **`APPEND` of guarded static branches**: one regular join
 * sub-query per `Arango::MAP` entry, each guarded by an equality on the
 * discriminator field so only the matching branch yields rows. The resulting
 * `LET` therefore always holds an **array** — exactly like a regular join — and
 * the projection layer ({@see \oihana\arango\db\helpers\fields\aqlFieldObject()})
 * unwraps it with `FIRST()` for a `Filter::JOIN` or keeps the whole array for a
 * `Filter::JOINS`.
 *
 * Example output (single `Filter::JOIN`, two branches):
 * ```
 * LET area = APPEND(
 *   ( FOR doc_join IN warehouses
 *       FILTER doc_join._key == doc.selector.areaServed
 *          && doc.selector.areaScope == "…#Warehouse"
 *       RETURN { _key: doc_join._key, name: doc_join.name } ) ,
 *   ( FOR doc_join IN subsidiaries
 *       FILTER doc_join._key == doc.selector.areaServed
 *          && doc.selector.areaScope == "…#Company"
 *       RETURN { _key: doc_join._key, name: doc_join.name } )
 * )
 * ```
 *
 * Each branch is delegated to {@see buildJoinSubquery()}, so the whole join
 * machinery (filtering, sorting, skinning, nested edges / joins) applies per
 * branch. The parent key path (`Arango::PROPERTY`, defaulting to `$name`) and an
 * optional foreign key attribute (`Arango::KEY`) declared at the top level are
 * shared as defaults across branches; a branch may override its own key.
 *
 * @param string|null             $name       The join field name — also the default `LET` variable
 *                                            name and the default parent key path.
 * @param array                   $definition The polymorphic join definition. Keys:
 * - `Arango::DISCRIMINATOR` (string)  Parent field path deciding the branch (required).
 * - `Arango::MAP` (array)             Non-empty `type => join-definition` table (required).
 * - `Arango::PROPERTY` (string|null)  Shared parent key path (default: `$name`).
 * - `Arango::KEY` (string|null)       Shared foreign key attribute (default per branch: `_key`).
 * - `Arango::UNIQUE` (string|null)    Optional `LET` variable name, overrides `$name`.
 * - `Arango::FALLBACK` (mixed|null)   Branch for unmatched discriminator values (later lot; `null` = none).
 * @param string                  $docRef     The AQL variable name of the main document reference.
 * @param ContainerInterface|null $container  Optional DI container used to resolve branch models.
 * @param array                   $init       Optional associative array used for variable initialization.
 * @param bool                    $isArray    If true, each branch matches an array of keys (`IN`).
 *
 * @return string The complete AQL 'LET' statement.
 *
 * @throws Exception                   If a branch sub-query cannot be built.
 * @throws ContainerExceptionInterface If a branch model cannot be resolved from the container.
 * @throws NotFoundExceptionInterface  If a branch model cannot be found in the container.
 * @throws ReflectionException         If a callable conditions closure fails reflection.
 * @throws UnexpectedValueException    If $name is empty, or the definition lacks a non-empty
 *                                     `Arango::MAP` / `Arango::DISCRIMINATOR`.
 *
 * @package oihana\arango\models\helpers\joins
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function buildPolymorphicJoinVariable
(
    ?string             $name        ,
    array               $definition  = [] ,
    string              $docRef      = AQL::DOC ,
    ?ContainerInterface $container   = null ,
    array               $init        = [] ,
    bool                $isArray     = false ,
)
: string
{
    if( empty( $name ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the name of the join attribute not must be null or empty.' ) ;
    }

    $map = $definition[ Arango::MAP ] ?? null ;
    if( !is_array( $map ) || $map === [] )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, a polymorphic join requires a non-empty Arango::MAP table.' ) ;
    }

    $discriminator = $definition[ Arango::DISCRIMINATOR ] ?? null ;
    if( !is_string( $discriminator ) || $discriminator === '' )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, a polymorphic join requires a non-empty Arango::DISCRIMINATOR field.' ) ;
    }

    $varName    = $definition[ Arango::UNIQUE   ] ?? $name ;
    $keyPath    = $definition[ Arango::PROPERTY ] ?? $name ; // shared parent key path
    $sharedKey  = $definition[ Arango::KEY      ] ?? null ;  // shared foreign key attribute

    $discriminatorRef = key( $discriminator , $docRef ) ;    // e.g. doc.selector.areaScope

    $subqueries = [] ;

    foreach( $map as $type => $branch )
    {
        if( !is_array( $branch ) )
        {
            throw new UnexpectedValueException
            (
                __FUNCTION__ . ' failed, the Arango::MAP branch "' . $type . '" must be a join definition array.'
            ) ;
        }

        // Share the top-level foreign key attribute unless the branch overrides it.
        if( $sharedKey !== null && !isset( $branch[ Arango::KEY ] ) )
        {
            $branch[ Arango::KEY ] = $sharedKey ;
        }

        // Guard: only the branch whose discriminator value matches yields rows.
        $guard = equal( $discriminatorRef , betweenDoubleQuotes( (string) $type ) ) ;

        $subqueries[] = betweenParentheses
        (
            buildJoinSubquery( $keyPath , $branch , $docRef , $container , $init , $isArray , [ $guard ] )
        ) ;
    }

    // Concatenate the branch arrays: only the matching branch is non-empty, so
    // the projection's FIRST() (Filter::JOIN) or the whole array (Filter::JOINS)
    // resolves to the right document(s).
    $combined = array_shift( $subqueries ) ;
    foreach( $subqueries as $subquery )
    {
        $combined = append( $combined , $subquery ) ;
    }

    return aqlLet( $varName , $combined , false ) ;
}
