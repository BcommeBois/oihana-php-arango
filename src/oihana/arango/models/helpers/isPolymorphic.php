<?php

namespace oihana\arango\models\helpers;

use oihana\arango\enums\Arango;

/**
 * Tells whether a relation definition — a **join** or an **edge** — is
 * *polymorphic*, i.e. its target collection is chosen at query time from a
 * discriminator field of the parent document / start vertex.
 *
 * A polymorphic relation replaces the single `AQL::MODEL` by:
 * - `Arango::DISCRIMINATOR` — the parent field path deciding the branch
 *   (e.g. `selector.areaScope` for a join, `kind` for an edge) ;
 * - `Arango::MAP` — a non-empty `type => relation-definition` table, one branch
 *   per discriminator value (each branch is itself a regular relation definition) ;
 * - `Arango::FALLBACK` (optional) — the branch used when the discriminator value
 *   matches no `Arango::MAP` key.
 *
 * @param mixed $definition The resolved relation definition (usually an array).
 *
 * @return bool `true` when the definition carries a non-empty `Arango::MAP`
 *              and a non-empty string `Arango::DISCRIMINATOR`, `false` otherwise.
 *
 * @example
 * ```php
 * use function oihana\arango\models\helpers\isPolymorphic;
 * use oihana\arango\enums\Arango;
 * use oihana\arango\db\enums\AQL;
 *
 * isPolymorphic([ AQL::MODEL => 'model.warehouse' ]);              // false — regular relation
 * isPolymorphic([
 *     Arango::DISCRIMINATOR => 'kind',
 *     Arango::MAP           => [ 'warehouse' => [ AQL::MODEL => 'model.warehouse' ] ],
 * ]);                                                               // true
 * ```
 *
 * @package oihana\arango\models\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isPolymorphic( mixed $definition ) : bool
{
    return is_array( $definition )
        && isset( $definition[ Arango::MAP ] )
        && is_array( $definition[ Arango::MAP ] )
        && $definition[ Arango::MAP ] !== []
        && isset( $definition[ Arango::DISCRIMINATOR ] )
        && is_string( $definition[ Arango::DISCRIMINATOR ] )
        && $definition[ Arango::DISCRIMINATOR ] !== '' ;
}
