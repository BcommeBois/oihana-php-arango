<?php

namespace oihana\arango\db\helpers;

/**
 * Tells whether a string is a safe AQL **variable** name — the identifier a
 * `LET` binds, not a path to an attribute.
 *
 * The distinction matters, and {@see isAttributeName()} is the wrong guard for
 * this job: it validates a *path*, so it accepts `address.city`, which reads
 * perfectly as an attribute and is a syntax error as a variable
 * (`LET address.city = …`). A variable name is a single identifier: a letter or
 * an underscore, then letters, digits and underscores.
 *
 * Two failures this cannot see, because they are not about shape:
 *
 * - an **AQL keyword** (`LET`, `RETURN`, `FILTER`, …) has the shape of an
 *   identifier and is refused by the server (`expecting identifier`);
 * - a name **already bound** in the query — `doc` above all — parses, then
 *   fails on execution (`variable 'doc' is assigned multiple times`).
 *
 * Both are refused loudly by ArangoDB at the first query, with a message naming
 * the variable, so they surface immediately rather than corrupting a result.
 * Enumerating the keyword list here would only add a copy to keep in sync with
 * the server, and a false sense of completeness.
 *
 * @param mixed $value The candidate variable name.
 *
 * @return bool Whether it is a well-formed AQL variable name.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\isVariableName;
 *
 * isVariableName( 'authorRef'    ) ; // true
 * isVariableName( '_ref'         ) ; // true
 * isVariableName( 'address.city' ) ; // false — a path, not an identifier
 * isVariableName( '1ref'         ) ; // false — a digit cannot open an identifier
 * isVariableName( 'my-ref'       ) ; // false
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
function isVariableName( mixed $value ): bool
{
    if ( !is_string( $value ) || $value === '' )
    {
        return false ;
    }

    return (bool) preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/' , $value ) ;
}
