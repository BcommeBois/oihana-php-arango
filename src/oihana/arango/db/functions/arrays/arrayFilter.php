<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\Operation;
use oihana\enums\Char;

/**
 * Build an AQL array inline filter `array[* FILTER <condition>]`.
 *
 * This is AQL's array expansion in its filtering form: it keeps the elements of
 * `$array` for which `$condition` (which typically references the loop variable
 * {@see \oihana\arango\db\enums\Clause::CURRENT}) holds, yielding a sub-array. It
 * is the filtering sibling of {@see arrayMap()} (`[* RETURN expr]`).
 *
 * Combined with {@see length()} it expresses an existential test —
 * `LENGTH(doc.items[* FILTER CURRENT.active]) > 0` — though the boolean array
 * "question mark" operator (`[? FILTER …]`, see {@see arrayContains()}) is a more
 * direct way to write the same intent.
 *
 * `$condition` is interpolated verbatim — callers are responsible for building it
 * from trusted/whitelisted pieces (bound values, validated fields, …).
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\arrayFilter;
 *
 * echo arrayFilter( 'doc.contactPoint' , 'CURRENT.email != null' );
 * // doc.contactPoint[* FILTER CURRENT.email != null]
 * ```
 *
 * @param string $array     The array expression to expand (e.g. `doc.contactPoint`).
 * @param string $condition The FILTER condition tested on each element (e.g. `CURRENT.email != null`).
 *
 * @return string The formatted AQL inline filter.
 *
 * @see arrayMap() For the projecting form `[* RETURN expr]`.
 * @see https://docs.arangodb.com/stable/aql/operators/#array-expansion
 *
 * @package oihana\arango\db\functions\arrays
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function arrayFilter( string $array , string $condition ) : string
{
    return $array . Char::LEFT_BRACKET . Char::ASTERISK . Char::SPACE
         . Operation::FILTER . Char::SPACE . $condition . Char::RIGHT_BRACKET ;
}
