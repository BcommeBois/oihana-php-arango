<?php

namespace oihana\arango\models\helpers;

use oihana\arango\enums\Field;
use oihana\arango\models\Documents;

/**
 * Re-applies the **target model**'s `Field::REQUIRES` gates to a relation's
 * projection (T6).
 *
 * When an edge/join definition declares its **own** projection
 * (`AQL::FIELDS` / `AQL::SKIN_FIELDS`), that ad-hoc field list *replaces* the
 * target model's `$fields` — so the `Field::REQUIRES` markers carried by the
 * target model are not applied, and a field hidden from reading could be
 * re-projected in clear through the relation. This helper closes that hole by
 * dropping every projected field whose **source** attribute is refused by the
 * target model's own projection ({@see isPathAuthorized()} against
 * `$documents->fields`), mirroring the read-side gate applied everywhere else.
 *
 * The **source** attribute — `Field::NAME` when the field aliases a document
 * attribute, otherwise the output key — is what gets gated, never the output
 * label, so an alias cannot dodge (or borrow) the wrong permission.
 *
 * Fail-open and idempotent: with no target model, no `$documents->fields`, a
 * field carrying no `Field::REQUIRES` on the target, or no authorizer injected,
 * the field is kept — and re-running it on a projection that already came from
 * `$documents->fields` changes nothing.
 *
 * @param array<array-key,mixed>|null $fields    The relation's resolved projection (mutated copy returned).
 * @param Documents|null              $documents The resolved target model (reads its `$fields`).
 * @param array<array-key,mixed>      $init      The request-level init. Reads `Arango::AUTHORIZER`.
 *
 * @return array<array-key,mixed>|null The projection with the target-refused fields removed.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models\helpers
 */
function authorizeTargetFields( ?array $fields , ?Documents $documents , array $init = [] ) : ?array
{
    if ( !is_array( $fields ) || $fields === [] || !( $documents instanceof Documents ) )
    {
        return $fields ;
    }

    $targetFields = $documents->fields ;

    foreach ( $fields as $key => $options )
    {
        $source = is_array( $options ) ? ( $options[ Field::NAME ] ?? $key ) : $key ;

        if ( !isPathAuthorized( (string) $source , $targetFields , $init ) )
        {
            unset( $fields[ $key ] ) ;
        }
    }

    return $fields ;
}
