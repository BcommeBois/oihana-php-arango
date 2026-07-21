<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\enums\Field;

use function oihana\arango\models\helpers\isPathAuthorized;

/**
 * Decides whether a conditional projection would **read** a field the caller may
 * not read — an inference oracle through a condition (T5). The field that *carries*
 * the condition is already gated by {@see \oihana\arango\db\helpers\isAuthorized()};
 * this closes the complementary hole where the condition *reads* a masked field:
 *
 * - {@see Field::WHEN} — the boolean guard (`price: doc.secretFlag == true ? … : …`):
 *   its attributes are read at the current projection level, gated against `$fields`;
 * - {@see Field::ELSE} — the fallback branch declaring a `Field::PROPERTY`
 *   (`cond ? … : doc.secretAttr`): a **direct** leak, gated against `$fields`;
 * - {@see Field::WHERE} — the `Filter::MAP` element filter (`FOR item … FILTER
 *   item.region == …`): its attributes read the array **elements**, so they are
 *   gated against the map's own sub-fields ({@see Field::FIELDS}).
 *
 * Fail-open, exactly like the projection: an attribute absent from the projection,
 * carrying no `Field::REQUIRES`, or with no authorizer injected, is allowed — only a
 * field explicitly gated and refused makes this return `true`. The caller then drops
 * the whole conditional field (fail-closed), never emitting a partial oracle.
 *
 * @param array<array-key,mixed>  $options The field definition (reads WHEN / ELSE / WHERE / FIELDS).
 * @param array<array-key,mixed>|null $fields The current projection map (the WHEN/ELSE context).
 * @param array<array-key,mixed>  $init    The request-level init. Reads `Arango::AUTHORIZER`.
 *
 * @return bool `true` when a read attribute is refused (drop the field), `false` otherwise.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\db\helpers\fields
 * @since   1.6.0
 */
function conditionReadsDeniedField( array $options , ?array $fields , array $init ) : bool
{
    // WHEN + ELSE — read at the current projection level, gated against $fields.
    $attributes = [] ;

    if ( isset( $options[ Field::WHEN ] ) )
    {
        $attributes = collectWhenAttributes( $options[ Field::WHEN ] ) ;
    }

    $else = $options[ Field::ELSE ] ?? null ;
    if ( is_array( $else ) && isset( $else[ Field::PROPERTY ] ) && is_string( $else[ Field::PROPERTY ] ) )
    {
        $attributes[] = $else[ Field::PROPERTY ] ;
    }

    foreach ( $attributes as $attribute )
    {
        if ( !isPathAuthorized( $attribute , $fields , $init ) )
        {
            return true ;
        }
    }

    // WHERE — reads the array elements of a Filter::MAP, gated against the map's own
    // sub-fields (`item.<sub>` refers to Field::FIELDS, not the current level).
    if ( isset( $options[ Field::WHERE ] ) )
    {
        $subFields = $options[ Field::FIELDS ] ?? null ;
        $subFields = is_array( $subFields ) ? $subFields : null ;

        foreach ( collectWhenAttributes( $options[ Field::WHERE ] ) as $attribute )
        {
            if ( !isPathAuthorized( $attribute , $subFields , $init ) )
            {
                return true ;
            }
        }
    }

    return false ;
}
