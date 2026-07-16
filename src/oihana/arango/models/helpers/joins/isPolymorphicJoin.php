<?php

namespace oihana\arango\models\helpers\joins;

use oihana\arango\enums\Arango;

/**
 * Tells whether a join definition is *polymorphic* — i.e. its target collection
 * is chosen at query time from a discriminator field of the parent document.
 *
 * A polymorphic join replaces the single `AQL::MODEL` of a regular join by:
 * - `Arango::DISCRIMINATOR` — the parent field path deciding the branch
 *   (e.g. `selector.areaScope`) ;
 * - `Arango::MAP` — a non-empty `type => join-definition` table, one branch per
 *   discriminator value (each branch is itself a regular join definition) ;
 * - `Arango::FALLBACK` (optional) — the branch used when the discriminator value
 *   matches no `Arango::MAP` key.
 *
 * @param mixed $definition The resolved join definition (usually an array).
 *
 * @return bool `true` when the definition carries a non-empty `Arango::MAP`
 *              and a non-empty string `Arango::DISCRIMINATOR`, `false` otherwise.
 *
 * @example
 * ```php
 * use function oihana\arango\models\helpers\joins\isPolymorphicJoin;
 * use oihana\arango\enums\Arango;
 * use oihana\arango\db\enums\AQL;
 *
 * isPolymorphicJoin([ AQL::MODEL => 'model.warehouse' ]); // false — regular join
 * isPolymorphicJoin // true
 * ([
 *     Arango::DISCRIMINATOR => 'selector.areaScope',
 *     Arango::MAP           => [ 'Warehouse' => [ AQL::MODEL => 'model.warehouse' ] ],
 * ]);
 * ```
 *
 * @package oihana\arango\models\helpers\joins
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isPolymorphicJoin( mixed $definition ) : bool
{
    return is_array( $definition )
        && isset( $definition[ Arango::MAP ] )
        && is_array( $definition[ Arango::MAP ] )
        && $definition[ Arango::MAP ] !== []
        && isset( $definition[ Arango::DISCRIMINATOR ] )
        && is_string( $definition[ Arango::DISCRIMINATOR ] )
        && $definition[ Arango::DISCRIMINATOR ] !== '' ;
}
