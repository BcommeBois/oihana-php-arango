<?php

namespace oihana\arango\models\helpers;

use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;

/**
 * Drops the relation markers whose edge/join definition is denied by the
 * request-scoped authorizer (definition-level gating, `AQL::REQUIRES` on the
 * definition itself).
 *
 * A relation is emitted by two parallel walks that must stay symmetric :
 * the `LET` sub-query (buildVariables) and the projected key (aqlFields).
 * When a definition declares `AQL::REQUIRES` and the authorizer denies it,
 * the `LET` is not emitted — so the matching marker must also disappear from
 * the projected fields, otherwise the `RETURN` would reference an unbound
 * variable. This helper performs that field-side purge : it is applied at
 * every point where a prepared fields array meets its edges/joins registries,
 * right before buildVariables() / aqlFields() run on them.
 *
 * Resolution mirrors buildVariables() : the definition is looked up in the
 * registry under the field key, a string value being a one-hop alias to
 * another registry entry. A marker without a resolvable array definition is
 * left untouched (buildVariables skips it anyway — nothing to desynchronize).
 *
 * The check itself is delegated to {@see isAuthorized()} with the whole
 * definition (its `AQL::REQUIRES` key equals `Field::REQUIRES`), so the
 * semantics are exactly the field-level ones : no marker → allowed, no
 * authorizer → allowed (fail open), a list of subjects → logical OR. The
 * definition-level gate COMPOSES with the field-level `Field::REQUIRES`
 * (evaluated downstream) : either level can drop the relation.
 *
 * The function is pure and idempotent : applying it twice — e.g. once on the
 * `LET` walk and once on the projection walk of a wrapped reference — yields
 * the same fields, which is precisely what keeps both walks symmetric.
 *
 * @param array<string,mixed>|null $fields The prepared query fields (normalized definitions).
 * @param array<string,mixed>|null $edges  The edges registry paired with the fields.
 * @param array<string,mixed>|null $joins  The joins registry paired with the fields.
 * @param array<array-key,mixed>   $init   The request-level init array (reads `Arango::AUTHORIZER`).
 *
 * @return array<string,mixed>|null The fields with denied relation markers removed.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models\helpers
 */
function authorizeRelationFields( ?array $fields , ?array $edges , ?array $joins , array $init = [] ) : ?array
{
    if ( empty( $fields ) )
    {
        return $fields ;
    }

    foreach ( $fields as $key => $field )
    {
        $filter = is_array( $field ) ? ( $field[ Field::FILTER ] ?? null ) : null ;

        $registry = match ( $filter )
        {
            Filter::EDGE , Filter::EDGES , Filter::EDGES_COUNT => $edges ?? [] ,
            Filter::JOIN , Filter::JOINS                       => $joins ?? [] ,
            default                                            => null ,
        } ;

        if ( $registry === null )
        {
            continue ; // not a relation marker
        }

        $definition = $registry[ $key ] ?? null ;
        if ( is_string( $definition ) )
        {
            $definition = $registry[ $definition ] ?? null ; // one-hop alias, as in buildVariables()
        }

        if ( is_array( $definition ) && !isAuthorized( $definition , $init ) )
        {
            unset( $fields[ $key ] ) ;
        }
    }

    return $fields ;
}
