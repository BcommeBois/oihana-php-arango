<?php

namespace oihana\arango\db\helpers;

use oihana\arango\models\enums\filters\FilterParam;

/**
 * Resolve the `alt` parameter into its key-side and value-side chains.
 *
 * Three backward-compatible forms are supported:
 * - `"lower"` / `["trim","lower"]` (string or list) → key side only, the value is left untouched.
 * - `{ "key":<chain>, "val":<chain> }` (object) → explicit chain per side.
 * - `{ "key":<chain>, "val":true }` → `val:true` mirrors the key-side chain onto the value side.
 *
 * The object form is told apart from a plain function chain by being an
 * associative array (a list is a function chain, an associative array is the
 * per-side object). Shared by the filter and facet builders
 * ({@see \oihana\arango\models\traits\aql\FilterTrait},
 * {@see \oihana\arango\models\traits\aql\FacetTrait}) and the inline-condition helpers.
 *
 * @param mixed $alt The raw `alt` parameter.
 *
 * @return array{0:mixed,1:mixed} A `[ keyChain , valChain ]` pair; either entry is null for a no-op on that side.
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function resolveAltSides( mixed $alt ): array
{
    if ( $alt === null )
    {
        return [ null , null ] ;
    }

    // Object form { key:<chain>, val:<chain|true> } — an associative array, as
    // opposed to a plain function chain (a list).
    if ( is_array( $alt ) && !array_is_list( $alt ) )
    {
        $keyChain = $alt[ FilterParam::KEY ] ?? null ;
        $valChain = $alt[ FilterParam::VAL ] ?? null ;

        // val:true → mirror the key-side chain onto the value side.
        if ( $valChain === true )
        {
            $valChain = $keyChain ;
        }

        return [ $keyChain , $valChain ] ;
    }

    // String or list form → key side only, value untouched.
    return [ $alt , null ] ;
}
