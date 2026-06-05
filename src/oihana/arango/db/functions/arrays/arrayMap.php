<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\Operation;
use oihana\enums\Char;

/**
 * Build an AQL array inline projection `array[* RETURN <expression>]`.
 *
 * This is AQL's array expansion in its projecting form: it maps each element of
 * `$array` to `$expression` (which typically references the loop variable
 * {@see Clause::CURRENT}), yielding a new array. It is the read-only sibling of
 * {@see arrayFilter()} (`[* FILTER cond]`).
 *
 * `$expression` is interpolated verbatim — callers are responsible for building
 * it from trusted/whitelisted pieces (e.g. `CURRENT`, an `alterExpression()`
 * chain, or a validated sub-field). {@see pluck()} is the safe specialization
 * for a single sub-field (it validates the field name first).
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\arrayMap;
 *
 * echo arrayMap( 'doc.tags' , 'LOWER(CURRENT)' );        // doc.tags[* RETURN LOWER(CURRENT)]
 * echo arrayMap( '@value'   , 'LOWER(CURRENT)' );        // @value[* RETURN LOWER(CURRENT)]
 * echo arrayMap( 'doc.items', 'CURRENT.price' );         // doc.items[* RETURN CURRENT.price]
 * ```
 *
 * @param string $array      The array expression to expand (e.g. `doc.tags`, `@value`).
 * @param string $expression The expression returned for each element (e.g. `LOWER(CURRENT)`).
 *
 * @return string The formatted AQL inline projection.
 *
 * @see arrayFilter() For the filtering form `[* FILTER cond]`.
 * @see pluck()       For the safe single-sub-field projection.
 * @see https://docs.arangodb.com/stable/aql/operators/#array-expansion
 *
 * @package oihana\arango\db\functions\arrays
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function arrayMap( string $array , string $expression ) : string
{
    return $array . Char::LEFT_BRACKET . Char::ASTERISK . Char::SPACE
         . Operation::RETURN . Char::SPACE . $expression . Char::RIGHT_BRACKET ;
}
