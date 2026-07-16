<?php

namespace oihana\arango\models\helpers;

use oihana\arango\enums\Field;

/**
 * Resolves, permission-gates and stamps a relation definition (edge or join)
 * before it is handed to a relation builder — the common preamble shared by the
 * `Filter::EDGE(S)` and `Filter::JOIN(S)` branches of {@see buildVariables()}.
 *
 * Steps:
 * 1. Look the definition up in `$registry` (`$edges` or `$joins`) by `$key`;
 *    a **string** value is a shortcut reference to another entry, dereferenced once.
 * 2. Return `null` when nothing resolves (the caller skips the relation).
 * 3. **Two composable gates** (logical AND): `Field::REQUIRES` on the FIELDS entry
 *    (`$field`) and `AQL::REQUIRES` on the definition itself. Either one denied
 *    returns `null`, dropping the relation from both the `LET` walk and — mirrored
 *    upstream by {@see authorizeRelationFields()} — the projection.
 * 4. Stamp `Field::UNIQUE` onto the definition (the caller-provided AQL variable
 *    name override).
 *
 * A `Filter::EDGES_COUNT` is gated the same way: its count `LET` is dropped only
 * when the entry (or the shared definition) declares a requirement — otherwise the
 * cardinality stays visible, as knowing it is rarely a leak.
 *
 * @param array|null            $registry The relation registry — `$edges` or `$joins`.
 * @param int|string            $key      The field key naming the relation.
 * @param array                 $field    The FIELDS entry (read for `Field::REQUIRES` / gating).
 * @param mixed                 $unique   The AQL variable name override stamped as `Field::UNIQUE`.
 * @param array                 $init     The request-level init array (reads `Arango::AUTHORIZER`).
 *
 * @return array|null The prepared definition, or `null` when it does not resolve or is denied.
 *
 * @package oihana\arango\models\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function prepareRelationDefinition( ?array $registry , int|string $key , array $field , mixed $unique , array $init ) : ?array
{
    $definition = $registry[ $key ] ?? null ;

    if( is_string( $definition ) )
    {
        // shortcut reference -> use another definition
        $definition = $registry[ $definition ] ?? null ;
    }

    if( !is_array( $definition ) )
    {
        return null ;
    }

    // Two composable gates: Field::REQUIRES on the FIELDS entry and AQL::REQUIRES
    // on the definition itself. Either level can drop the relation.
    if( !isAuthorized( $field , $init ) || !isAuthorized( $definition , $init ) )
    {
        return null ;
    }

    $definition[ Field::UNIQUE ] = $unique ;

    return $definition ;
}
