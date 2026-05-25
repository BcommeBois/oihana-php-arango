<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

/**
 * Resolves which projection an edge or join definition should use for the
 * active request skin.
 *
 * Resolution order :
 * 1. `AQL::SKIN_FIELDS[$skin]`             — explicit projection for this skin
 * 2. `AQL::SKIN_FIELDS['*']`               — fallback bucket inside SKIN_FIELDS
 * 3. `Arango::FIELDS`                      — legacy single projection (backwards-compatible)
 * 4. `null`                                — no projection declared at all
 *
 * If `AQL::SKIN_FIELDS` is absent or not an array, the function ignores it
 * and falls back directly on `Arango::FIELDS` — definitions that pre-date
 * the SKIN_FIELDS feature keep their behaviour unchanged.
 *
 * @param array       $definition The edge or join definition.
 * @param string|null $skin       The request-level skin (e.g. 'default', 'full').
 *
 * @return mixed The resolved projection (typically an array<string, mixed>) or null.
 *
 * @package oihana\arango\db\helpers
 * @author  Marc Alcaraz
 */
function resolveSkinFields( array $definition , ?string $skin ) :mixed
{
    $skinFields = $definition[ AQL::SKIN_FIELDS ] ?? null ;

    if ( is_array( $skinFields ) )
    {
        return $skinFields[ $skin ]
            ?? $skinFields[ '*'  ]
            ?? $definition[ Arango::FIELDS ]
            ?? null ;
    }

    return $definition[ Arango::FIELDS ] ?? null ;
}
