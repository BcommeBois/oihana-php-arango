<?php

namespace oihana\arango\migrations\helpers ;

/**
 * Builds the AQL that backfills a default value on every document where a
 * field is missing or `null` — the typical "new column" backfill.
 *
 * Emits `FOR doc IN <collection> FILTER doc.<field> == null UPDATE doc WITH {
 * <field>: <value> } IN <collection>`. The value is JSON-encoded, so a
 * string, number, boolean, array or object literal all work.
 *
 * Pure: returns the query string, runs nothing.
 *
 * @param string $collection The collection name.
 * @param string $field      The attribute to backfill.
 * @param mixed  $value      The default value (emitted as a JSON literal).
 *
 * @return string The ready-to-run AQL query.
 *
 * @package oihana\arango\migrations\helpers
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
function setDefaultQuery( string $collection , string $field , mixed $value ) : string
{
    return sprintf
    (
        'FOR doc IN %1$s FILTER doc.%2$s == null UPDATE doc WITH { %2$s: %3$s } IN %1$s' ,
        $collection ,
        $field ,
        json_encode( $value ) ,
    ) ;
}
