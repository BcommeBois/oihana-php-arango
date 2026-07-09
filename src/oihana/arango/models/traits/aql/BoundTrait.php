<?php

namespace oihana\arango\models\traits\aql;

/**
 * Declares the model's **bounds** whitelist — the numeric fields whose
 * `{ min, max }` extent may be computed over the filtered set.
 *
 * A bound is the numeric counterpart of a facet: where a facet count returns a
 * per-value distribution, a bound returns the two scalars that frame a field
 * (the lowest and highest value), typically to size a min/max range control
 * from the current result set. The whitelist is **fail-closed** — a `null`
 * `$bounds` makes nothing boundable, exactly like `$sortable` / `$groupable`.
 *
 * Each entry is keyed by the public field name and is either:
 * - a bare key (a flat scalar field, e.g. `width`) — its projection permission
 *   (`Field::REQUIRES`) is inherited from `$fields`, and
 * - a `[ Facet::PROPERTY => 'offers[*].price', Field::REQUIRES => '…' ]`
 *   definition for a nested measure reached through an array expansion `[*]`,
 *   with an optional own permission subject.
 *
 * The extent query is produced by
 * {@see \oihana\arango\models\traits\queries\BoundsQueryTrait}.
 *
 * @package oihana\arango\models\traits\aql
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait BoundTrait
{
    /**
     * The bounds whitelist (`field => true | definition`), or `null` when
     * nothing is boundable.
     */
    public ?array $bounds = [] ;

    /**
     * The 'bounds' parameter constant.
     */
    public const string BOUNDS = 'bounds' ;

    /**
     * Initialize the 'bounds' property.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeBounds( array $init = [] ):static
    {
        $this->bounds = $init[ self::BOUNDS ] ?? $this->bounds ;
        return $this ;
    }
}
