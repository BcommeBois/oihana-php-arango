<?php

namespace oihana\arango\migrations\helpers ;

/**
 * Builds the AQL that removes a top-level attribute from every document of a collection.
 *
 * Emits `FOR doc IN <collection> FILTER HAS(doc, "<field>") UPDATE doc WITH {
 * <field>: null } IN <collection> OPTIONS { keepNull: false }` —
 * `keepNull: false` strips the attribute instead of storing `null`.
 *
 * Pure: returns the query string, runs nothing.
 *
 * @param string $collection The collection name.
 * @param string $field      The attribute to remove.
 *
 * @return string The ready-to-run AQL query.
 *
 * @package oihana\arango\migrations\helpers
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
function dropFieldQuery( string $collection , string $field ) : string
{
    return sprintf
    (
        'FOR doc IN %1$s FILTER HAS( doc , %2$s ) UPDATE doc WITH { %3$s: null } IN %1$s OPTIONS { keepNull: false }' ,
        $collection ,
        json_encode( $field ) ,
        $field ,
    ) ;
}
