<?php

namespace oihana\arango\migrations\helpers ;

/**
 * Builds the AQL that renames a top-level attribute on every document of a
 * collection — the `up()` of a field rename.
 *
 * Emits `FOR doc IN <collection> FILTER HAS(doc, "<from>") UPDATE doc WITH {
 * <to>: doc.<from>, <from>: null } IN <collection> OPTIONS { keepNull: false }`
 * — the new attribute takes the old value, the old one is dropped
 * (`keepNull: false` removes it rather than storing `null`).
 *
 * Pure: returns the query string, runs nothing. A {@see \oihana\arango\migrations\Migration}
 * executes it (see `Migration::renameField()`).
 *
 * @param string $collection The collection name.
 * @param string $from       The current attribute name.
 * @param string $to         The new attribute name.
 *
 * @return string The ready-to-run AQL query.
 *
 * @package oihana\arango\migrations\helpers
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
function renameFieldQuery( string $collection , string $from , string $to ) : string
{
    return sprintf
    (
        'FOR doc IN %1$s FILTER HAS( doc , %2$s ) UPDATE doc WITH { %3$s: doc.%4$s, %4$s: null } IN %1$s OPTIONS { keepNull: false }' ,
        $collection ,
        json_encode( $from ) ,
        $to ,
        $from ,
    ) ;
}
