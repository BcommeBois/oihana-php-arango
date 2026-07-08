<?php

namespace oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operator;
use oihana\arango\enums\Field;
use oihana\enums\Char;

/**
 * Decides whether a *dotted* query attribute (a filter / facet / group path) is
 * allowed for the current request, by inheriting the projection permission of the
 * **exact sub-field** it targets — not only its root.
 *
 * This is the depth-aware counterpart of {@see isAttributeAuthorized()}: instead
 * of gating `explode('.', $path)[0]` (the root only), it walks the whole path
 * segment by segment, descending through the sub-fields of the structural fields
 * (`Field::FIELDS`, or the per-skin buckets `AQL::SKIN_FIELDS`) and reading the
 * optional `Field::REQUIRES` at **every** level. A single locked level anywhere on
 * the path denies the whole path — « what you cannot read, you cannot query on ».
 *
 * The decision at each level is deferred to the shared {@see isAuthorized()} gate
 * (backend-agnostic closure injected through `$init[Arango::AUTHORIZER]`), so the
 * semantics (OR over the subjects list, fail-open when no authorizer is injected)
 * match the field-level gate exactly.
 *
 * Resolution rules (aligned on {@see isAttributeAuthorized()}, extended in depth) :
 * - `$fields` is not an array → `true` (nothing to inherit, no gating).
 * - A segment is absent from the current field map(s), or only declared as a
 *   scalar / bool definition → `true` (no `Field::REQUIRES` to inherit here).
 * - A segment carries a `Field::REQUIRES` refused by the authorizer → `false`.
 * - Otherwise the walk descends into the sub-fields and continues; a path with no
 *   deeper declared sub-fields resolves to `true`.
 *
 * **Sub-fields resolution (fail-closed union).** A structural field can declare
 * its sub-fields under `Field::FIELDS` *and / or* under per-skin buckets
 * `AQL::SKIN_FIELDS`. Because a `Field::REQUIRES` is a permission (independent of
 * the projection skin), the descent gathers the sub-field from **every** source
 * and treats it as locked as soon as it is locked in **any one** of them — a
 * sub-field hidden in a single skin bucket stays gated on every path.
 *
 * A single-segment path behaves exactly like {@see isAttributeAuthorized()} — it
 * is its degenerate case. Each segment is stripped of the `[*]` array-expansion
 * marker, so the same helper serves the hierarchical filter leaves.
 *
 * @param string                      $path   The dotted public attribute path (e.g. `address.city`, `employee[*].salary`).
 * @param array<array-key,mixed>|null $fields The model projection map (`$this->fields`), keyed by field name.
 * @param array<array-key,mixed>      $init   The request-level init array. Reads `Arango::AUTHORIZER`.
 *
 * @return bool `true` when every level of the path is authorized, `false` as soon as one level is refused.
 *
 * @example
 * ```php
 * $fields =
 * [
 *     'address' =>
 *     [
 *         Field::FILTER => Filter::DOCUMENT ,
 *         Field::FIELDS => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ,
 *     ] ,
 * ] ;
 * isPathAuthorized( 'address.city' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ; // false
 * isPathAuthorized( 'address.zip'  , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ; // true (undeclared → ungated)
 * ```
 *
 * @package oihana\arango\models\helpers
 * @author  Marc Alcaraz (eKameleon)
 * @since   1.0.0
 */
function isPathAuthorized( string $path , ?array $fields , array $init = [] ) : bool
{
    if ( !is_array( $fields ) )
    {
        return true ;
    }

    $segments = explode( Char::DOT , $path ) ;
    $last     = count( $segments ) - 1 ;

    // Candidate field maps at the current depth. It is a *set* (not a single map)
    // so a sub-field declared across several SKIN_FIELDS buckets is all seen.
    $maps = [ $fields ] ;

    foreach ( $segments as $index => $segment )
    {
        $segment = str_replace( Operator::ARRAY_EXPANSION , Char::EMPTY , $segment ) ;

        // Every array declaration of this segment across the candidate maps.
        $definitions = [] ;
        foreach ( $maps as $map )
        {
            $definition = $map[ $segment ] ?? null ;
            if ( is_array( $definition ) )
            {
                $definitions[] = $definition ;
            }
        }

        // Not declared anywhere (or only as a scalar / bool) → no REQUIRES to
        // inherit at this level, nothing to gate.
        if ( empty( $definitions ) )
        {
            return true ;
        }

        // Fail-closed: locked in ANY declaration ⇒ the path is locked.
        foreach ( $definitions as $definition )
        {
            if ( !isAuthorized( $definition , $init ) )
            {
                return false ;
            }
        }

        if ( $index === $last )
        {
            return true ;
        }

        // Descend: gather the sub-field maps of every declaration — Field::FIELDS
        // plus every AQL::SKIN_FIELDS bucket — so a lock declared in any single
        // projection is still seen at the deeper levels.
        $maps = [] ;
        foreach ( $definitions as $definition )
        {
            $direct = $definition[ Field::FIELDS ] ?? null ;
            if ( is_array( $direct ) )
            {
                $maps[] = $direct ;
            }

            $buckets = $definition[ AQL::SKIN_FIELDS ] ?? null ;
            if ( is_array( $buckets ) )
            {
                foreach ( $buckets as $bucket )
                {
                    if ( is_array( $bucket ) )
                    {
                        $maps[] = $bucket ;
                    }
                }
            }
        }

        // No declared sub-fields anywhere → nothing deeper to gate.
        if ( empty( $maps ) )
        {
            return true ;
        }
    }

    // Unreachable: a non-empty path always returns on its last segment.
    // @codeCoverageIgnoreStart
    return true ;
    // @codeCoverageIgnoreEnd
}
