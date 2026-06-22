<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\Operator;

/**
 * Strips every array-expansion marker (`[*]`) from an attribute path, turning a
 * query-side traversal path into the flat, dotted path used to *declare* an
 * ArangoSearch link (or an inverted index).
 *
 * ArangoSearch (Community edition) indexes the sub-fields of array elements
 * natively when the link declares the sub-field **without** the `[*]` marker:
 * the server descends into the array on its own. The marker is only meaningful
 * in the AQL query (`doc.contactPoints[*].email IN TOKENS(...)`), never in the
 * link definition (`{ fields : { contactPoints : { fields : { email : {} } } } }`).
 * This helper bridges the two surfaces by removing all the markers, so the same
 * declared path can build the link (stripped) and the query (kept).
 *
 * It is the search/link counterpart of the `[*]` handling already performed by
 * the hierarchical `?filter=` builder, reusing the same {@see Operator::ARRAY_EXPANSION}
 * marker. Every marker is removed, whatever the nesting depth — a multi-level
 * path such as `employee[*].contactPoint[*].email` flattens to a plain dotted
 * path (non-correlated search; correlation would require Enterprise `nested`
 * fields, out of scope here).
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\stripArrayExpansion;
 *
 * stripArrayExpansion( 'name' );                                // 'name'
 * stripArrayExpansion( 'description.fr' );                      // 'description.fr'
 * stripArrayExpansion( 'contactPoints[*].email' );             // 'contactPoints.email'
 * stripArrayExpansion( 'employee[*].contactPoint[*].email' );  // 'employee.contactPoint.email'
 * ```
 *
 * @param string $path The attribute path, possibly carrying `[*]` markers.
 *
 * @return string The same path with every `[*]` marker removed.
 *
 * @package oihana\arango\db\helpers
 * @since   1.5.0
 * @author  Marc Alcaraz
 */
function stripArrayExpansion( string $path ) : string
{
    return str_replace( Operator::ARRAY_EXPANSION , '' , $path ) ;
}
