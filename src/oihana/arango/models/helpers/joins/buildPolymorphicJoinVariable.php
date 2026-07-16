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

use function oihana\arango\models\helpers\buildPolymorphicRelationVariable;
use function oihana\core\strings\betweenParentheses;

/**
 * Builds a single AQL 'LET' subquery for a *polymorphic* join — a join whose
 * target collection is chosen at query time from a discriminator field of the
 * parent document.
 *
 * The whole branch machinery (guarding, per-branch permission gating, the
 * `FALLBACK` branch, the `APPEND` combine) lives in the shared
 * {@see buildPolymorphicRelationVariable()}; this function only resolves the
 * join-specific shared defaults — the parent key path (`Arango::PROPERTY`,
 * defaulting to `$name`) and the foreign key attribute (`Arango::KEY`) — and
 * supplies the per-branch builder that wraps {@see buildJoinSubquery()}.
 *
 * The resulting `LET` always holds an **array** — exactly like a regular join —
 * so the projection layer ({@see \oihana\arango\db\helpers\fields\aqlFieldObject()})
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
 * @param string|null             $name       The join field name — also the default `LET` variable
 *                                            name and the default parent key path.
 * @param array                   $definition The polymorphic join definition. Keys:
 * - `Arango::DISCRIMINATOR` (string)  Parent field path deciding the branch (required).
 * - `Arango::MAP` (array)             Non-empty `type => join-definition` table (required).
 * - `Arango::PROPERTY` (string|null)  Shared parent key path (default: `$name`).
 * - `Arango::KEY` (string|null)       Shared foreign key attribute (default per branch: `_key`).
 * - `Arango::UNIQUE` (string|null)    Optional `LET` variable name, overrides `$name`.
 * - `Arango::FALLBACK` (array|null)   Join definition for unmatched discriminator values (`null` = none).
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
    $keyPath   = $definition[ Arango::PROPERTY ] ?? $name ; // shared parent key path
    $sharedKey = $definition[ Arango::KEY      ] ?? null ;  // shared foreign key attribute

    // Builds one guarded branch sub-query, sharing the top-level foreign key
    // attribute unless the branch overrides it. buildJoinSubquery returns an
    // unparenthesized body, so we wrap it here.
    $buildBranch = function( array $branch , string $guard )
        use ( $sharedKey , $keyPath , $docRef , $container , $init , $isArray ) : string
    {
        if( $sharedKey !== null && !isset( $branch[ Arango::KEY ] ) )
        {
            $branch[ Arango::KEY ] = $sharedKey ;
        }

        return betweenParentheses
        (
            buildJoinSubquery( $keyPath , $branch , $docRef , $container , $init , $isArray , [ $guard ] )
        ) ;
    } ;

    return buildPolymorphicRelationVariable( $name , $definition , $docRef , $init , $buildBranch ) ;
}
