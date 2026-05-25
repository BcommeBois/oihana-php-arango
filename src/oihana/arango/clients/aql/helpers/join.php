<?php

namespace oihana\arango\clients\aql\helpers ;

use oihana\arango\clients\aql\AqlLiteral ;
use oihana\arango\clients\aql\AqlQuery ;

/**
 * Joins a list of AQL fragments into a single {@see AqlQuery}, with bind
 * collision handling.
 *
 * Each entry of `$fragments` is interpreted the same way the {@see aql()}
 * helper interprets a value passed in its variadic tail:
 * - {@see AqlQuery}   — query string interpolated as-is, bind variables
 *                       merged into the resulting `AqlQuery` (with
 *                       per-fragment renaming on collision — see below).
 * - {@see AqlLiteral} — value inlined verbatim into the query string,
 *                       no bind.
 * - Anything else      — bound as a value parameter (`@jN`).
 *
 * Bind name collisions across fragments are resolved by prefixing the
 * offending names with `j{index}_` — the references inside the offending
 * fragment's query string are rewritten to match. The reserved
 * single-`@` vs double-`@@` syntax used to distinguish value binds from
 * collection binds is preserved.
 *
 * Empty input returns an empty {@see AqlQuery}; a single-entry input
 * goes through the same machinery so the caller can rely on the bind
 * map being well-formed regardless of `$fragments` length.
 *
 * Example — assembling N optional `FILTER` conditions:
 * ```php
 * $filters = [] ;
 *
 * if ( $onlyAdmins )  { $filters[] = aql( 'FILTER u.role == ?'   , 'admin' ) ; }
 * if ( $onlyActive )  { $filters[] = aql( 'FILTER u.active == ?' , true    ) ; }
 *
 * $query = aql
 * (
 *     'FOR u IN users ? RETURN u' ,
 *     new AqlLiteral( join( $filters )->query ) ,
 * ) ;
 * // (Or, more directly, assemble through Database::query() with a manually
 * //  built AqlQuery merging the joined fragment + the rest.)
 * ```
 *
 * The separator defaults to a single space (mirroring arangojs `aql.join`);
 * pass an explicit value to interleave keywords (`' AND '`, `', '`, …).
 *
 * @param array<int, mixed> $fragments Fragments to join.
 * @param string            $separator Separator interpolated between consecutive fragments (verbatim — like an {@see AqlLiteral}).
 *
 * @return AqlQuery
 */
function join( array $fragments , string $separator = ' ' ) : AqlQuery
{
    if ( $fragments === [] )
    {
        return new AqlQuery( '' , [] ) ;
    }

    $parts    = [] ;
    $bindVars = [] ;

    foreach ( $fragments as $index => $fragment )
    {
        if ( $fragment instanceof AqlQuery )
        {
            $renames    = [] ;
            $localQuery = $fragment->query ;

            foreach ( $fragment->bindVars as $name => $value )
            {
                $isCollection = is_string( $name ) && str_starts_with( $name , '@' ) ;
                $bareName     = $isCollection ? substr( $name , 1 ) : (string) $name ;

                $finalBare = $bareName ;
                $finalKey  = $isCollection ? '@' . $finalBare : $finalBare ;

                if ( array_key_exists( $finalKey , $bindVars ) )
                {
                    $finalBare = 'j' . $index . '_' . $bareName ;
                    $finalKey  = $isCollection ? '@' . $finalBare : $finalBare ;
                    $renames[ $bareName ] = $finalBare ;
                }

                $bindVars[ $finalKey ] = $value ;
            }

            if ( $renames !== [] )
            {
                $localQuery = preg_replace_callback
                (
                    '/(@@?)([a-zA-Z_][a-zA-Z0-9_]*)\b/' ,
                    static function ( array $matches ) use ( $renames ) : string
                    {
                        $bareName = $matches[ 2 ] ;
                        if ( !isset( $renames[ $bareName ] ) )
                        {
                            return $matches[ 0 ] ;
                        }
                        return $matches[ 1 ] . $renames[ $bareName ] ;
                    } ,
                    $localQuery ,
                ) ;
            }

            $parts[] = $localQuery ;
        }
        elseif ( $fragment instanceof AqlLiteral )
        {
            $parts[] = $fragment->value ;
        }
        else
        {
            $bindName = 'j' . $index ;
            $suffix   = 0 ;

            while ( array_key_exists( $bindName , $bindVars ) )
            {
                $bindName = 'j' . $index . '_' . ( ++$suffix ) ;
            }

            $bindVars[ $bindName ] = $fragment ;
            $parts[]               = '@' . $bindName ;
        }
    }

    return new AqlQuery( implode( $separator , $parts ) , $bindVars ) ;
}
