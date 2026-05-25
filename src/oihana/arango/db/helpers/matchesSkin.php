<?php

namespace oihana\arango\db\helpers;

/**
 * Tests whether a `Field::SKINS` marker matches the active request skin.
 *
 * Used internally by the AQL projection layer (FieldsTrait::filterFieldsBySkin)
 * to decide whether a field declared with `Field::SKINS => [...]` should be
 * projected for the current `?skin=` query parameter.
 *
 * Accepted shapes for `$skins` :
 * - `null`              — no skin restriction declared, always matches
 * - `array<string>`     — list of skins that activate the field, e.g. `[ Skin::DEFAULT , Skin::FULL ]`
 * - `string`            — comma-separated list, e.g. `"main,full"`
 *
 * String comparisons are strict and trimmed of surrounding whitespace.
 *
 * @param mixed   $skins       The `Field::SKINS` value from the field definition.
 * @param ?string $currentSkin The active request skin, or `null` when no skin is set.
 *
 * @return bool `true` if the field must be kept in the projection, `false` to drop it.
 *
 * @package oihana\arango\db\helpers
 * @author  Marc Alcaraz
 */
function matchesSkin( mixed $skins , ?string $currentSkin ) :bool
{
    if ( $skins === null || $currentSkin === null )
    {
        return true ;
    }

    if ( is_array( $skins ) )
    {
        return in_array( $currentSkin , $skins , true ) ;
    }

    if ( is_string( $skins ) )
    {
        return in_array( $currentSkin , array_map( 'trim' , explode( ',' , $skins ) ) , true ) ;
    }

    return true ;
}
