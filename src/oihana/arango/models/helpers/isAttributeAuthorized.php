<?php

namespace oihana\arango\models\helpers;

/**
 * Decides whether a query attribute (a filter / facet / group key) is allowed for
 * the current request, by inheriting the permission of the homonymous projection field.
 *
 * The whitelists that gate *what can be queried* (`AQL::FILTERS`, `Arango::FACETS`,
 * `Arango::GROUPABLE`) are disjoint from the projection permission (`Field::REQUIRES`
 * on `$fields`). Without this bridge, a field declared queryable but hidden from
 * reading could still be filtered / faceted / grouped on, and the presence, the
 * distribution or the order of the results would leak the hidden value (an *oracle*).
 *
 * This helper closes that gap the same way {@see \oihana\arango\models\traits\aql\SortTrait}
 * does for sorting: it looks the attribute up in the projection map `$fields` and, when
 * that field carries a `Field::REQUIRES` subject, defers the decision to the shared
 * {@see isAuthorized()} gate (backend-agnostic closure injected through
 * `$init[Arango::AUTHORIZER]`). Devise: « what you cannot read, you cannot query on ».
 *
 * Resolution rules (aligned on the field-level semantics):
 * - `$fields` is not an array, or the attribute is absent / not an array definition
 *   → `true` (nothing to inherit, no gating).
 * - The field definition carries no `Field::REQUIRES` → `true` (no gating).
 * - Otherwise → the result of {@see isAuthorized()} (OR over the subjects list,
 *   fail-open when no authorizer is injected).
 *
 * @param string                      $key    The public attribute key (already whitelisted by the surface).
 * @param array<array-key,mixed>|null $fields The model projection map (`$this->fields`), keyed by field name.
 * @param array<array-key,mixed>      $init   The request-level init array. Reads `Arango::AUTHORIZER`.
 *
 * @return bool `true` when the attribute may be queried, `false` when the inherited subject is refused.
 *
 * @example
 * ```php
 * $fields = [ 'name' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
 * isAttributeAuthorized( 'name'   , $fields , $init ) ; // true  (ungated)
 * isAttributeAuthorized( 'salary' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ; // false
 * ```
 *
 * @package oihana\arango\models\helpers
 * @author  Marc Alcaraz (eKameleon)
 * @since   1.0.0
 */
function isAttributeAuthorized( string $key , ?array $fields , array $init = [] ) : bool
{
    if ( !is_array( $fields ) )
    {
        return true ;
    }

    $definition = $fields[ $key ] ?? null ;

    if ( !is_array( $definition ) )
    {
        return true ;
    }

    return isAuthorized( $definition , $init ) ;
}
