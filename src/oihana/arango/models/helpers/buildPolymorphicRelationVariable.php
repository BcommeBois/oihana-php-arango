<?php

namespace oihana\arango\models\helpers;

use oihana\enums\Char;
use UnexpectedValueException;

use oihana\arango\enums\Arango;

use function oihana\arango\db\functions\arrays\append;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\notIn;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\key;

/**
 * Assembles the AQL 'LET' of a *polymorphic* relation — a join or an edge whose
 * target collection is chosen at query time from a discriminator field — shared
 * by {@see \oihana\arango\models\helpers\joins\buildPolymorphicJoinVariable()}
 * and {@see \oihana\arango\models\helpers\edges\buildPolymorphicEdgeVariable()}.
 *
 * AQL forbids a computed collection in `FOR … IN …`, so the relation is compiled
 * as an **`APPEND` of guarded static branches**: one sub-query per `Arango::MAP`
 * entry, each guarded by an equality on the discriminator so only the matching
 * branch yields rows. The `$buildBranch` callback is the only relation-specific
 * part — it turns a branch definition + its guard into a **parenthesized**
 * sub-query string (a join wraps `buildJoinSubquery()`, an edge delegates to the
 * already-parenthesized `buildEdgeSubquery()`).
 *
 * Security (fail-closed):
 * - **Per-branch gate** — a branch denied by `isAuthorized()` (`Field::REQUIRES`
 *   / `AQL::REQUIRES`) is dropped from the `APPEND`; its collection is never
 *   queried, so neither a value nor an existence bit of the hidden type leaks.
 * - **Fallback** — an optional `Arango::FALLBACK` branch catches discriminator
 *   values matching none of the DECLARED types, guarded by `NOT IN [ … ]` over
 *   **all** map keys (gated or not), so a document of a denied type routes to
 *   nothing, never to the fallback (no oracle).
 * - When every branch is dropped the `LET` holds `[]`, so the projection resolves
 *   to `null` / `[]` rather than a broken statement.
 *
 * @param string|null $name       The relation field name (also the default `LET` variable name).
 * @param array       $definition The polymorphic definition. Keys:
 * - `Arango::DISCRIMINATOR` (string)  Parent / start-vertex field deciding the branch (required).
 * - `Arango::MAP` (array)             Non-empty `type => relation-definition` table (required).
 * - `Arango::UNIQUE` (string|null)    Optional `LET` variable name, overrides `$name`.
 * - `Arango::FALLBACK` (array|null)   Definition for unmatched discriminator values (`null` = none).
 * @param string      $ref        The AQL variable name carrying the discriminator (`docRef` for a join,
 *                                the start vertex for an edge).
 * @param array       $init       The request-level init array (reads `Arango::AUTHORIZER`).
 * @param callable    $buildBranch `fn(array $branch, string $guard): string` — builds one parenthesized,
 *                                guarded branch sub-query. Called only on array branches that pass the gate.
 *
 * @return string The complete AQL 'LET' statement.
 *
 * @throws UnexpectedValueException If `$name` is empty, `Arango::MAP` / `Arango::DISCRIMINATOR` is missing
 *                                  or invalid, a map branch is not an array, or `Arango::FALLBACK` is a
 *                                  non-array, non-null value.
 *
 * @package oihana\arango\models\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function buildPolymorphicRelationVariable
(
    ?string  $name       ,
    array    $definition ,
    string   $ref        ,
    array    $init       ,
    callable $buildBranch
)
: string
{
    if( empty( $name ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the name of the relation not must be null or empty.' ) ;
    }

    $map = $definition[ Arango::MAP ] ?? null ;
    if( !is_array( $map ) || $map === [] )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, a polymorphic relation requires a non-empty Arango::MAP table.' ) ;
    }

    $discriminator = $definition[ Arango::DISCRIMINATOR ] ?? null ;
    if( !is_string( $discriminator ) || $discriminator === '' )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, a polymorphic relation requires a non-empty Arango::DISCRIMINATOR field.' ) ;
    }

    $varName          = $definition[ Arango::UNIQUE ] ?? $name ;
    $discriminatorRef = key( $discriminator , $ref ) ; // e.g. doc.selector.areaScope / doc.kind

    $subqueries = [] ;

    foreach( $map as $type => $branch )
    {
        if( !is_array( $branch ) )
        {
            throw new UnexpectedValueException
            (
                __FUNCTION__ . ' failed, the Arango::MAP branch "' . $type . '" must be a relation definition array.'
            ) ;
        }

        // Per-branch permission gate (fail-closed): a denied branch is dropped
        // from the union — its collection is never queried, so neither a value
        // nor an existence bit of the hidden type can leak. It composes (AND)
        // with the field- / definition-level gates applied to the whole relation.
        if( !isAuthorized( $branch , $init ) )
        {
            continue ;
        }

        // Guard: only the branch whose discriminator value matches yields rows.
        $guard = equal( $discriminatorRef , betweenDoubleQuotes( (string) $type ) ) ;

        $subqueries[] = $buildBranch( $branch , $guard ) ;
    }

    // Optional fallback branch — used when the discriminator value matches none
    // of the DECLARED types (all map keys, gated or not: a denied type routes to
    // nothing, never to the fallback).
    $fallback = $definition[ Arango::FALLBACK ] ?? null ;
    if( $fallback !== null )
    {
        if( !is_array( $fallback ) )
        {
            throw new UnexpectedValueException
            (
                __FUNCTION__ . ' failed, Arango::FALLBACK must be a relation definition array or null.'
            ) ;
        }

        if( isAuthorized( $fallback , $init ) )
        {
            $knownTypes    = array_map( fn( $type ) => betweenDoubleQuotes( (string) $type ) , array_keys( $map ) ) ;
            $fallbackGuard = notIn( $discriminatorRef , Char::LEFT_BRACKET . implode( Char::COMMA , $knownTypes ) . Char::RIGHT_BRACKET ) ;

            $subqueries[] = $buildBranch( $fallback , $fallbackGuard ) ;
        }
    }

    // Every branch gated out — emit an empty array so the projection resolves to
    // null (single) / [] (list), never a broken `LET`.
    if( $subqueries === [] )
    {
        return aqlLet( $varName , aqlArray() ) ;
    }

    // Concatenate the branch arrays: only the matching branch is non-empty, so
    // the projection's FIRST() (single) or the whole array (list) resolves to
    // the right document(s) / vertex(es).
    $combined = array_shift( $subqueries ) ;
    foreach( $subqueries as $subquery )
    {
        $combined = append( $combined , $subquery ) ;
    }

    return aqlLet( $varName , $combined ) ;
}
