<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\Operation;
use oihana\enums\Char;

/**
 * Build an AQL array "question mark" inline filter `array[? <quantifier> FILTER <condition>]`.
 *
 * Unlike {@see arrayFilter()} (`[* FILTER cond]`, which returns the matching
 * sub-array), the question-mark operator returns a **boolean**: whether the
 * array contains elements satisfying `$condition` under the given quantifier. It
 * is the direct, idiomatic way to write the existential `LENGTH(array[* FILTER
 * cond]) > 0` and, with a quantifier, the `ALL` / `NONE` / `AT LEAST (n)` variants.
 *
 * Supported quantifiers (omitted = "at least one"):
 * - `''`            → at least one element matches (default);
 * - `ANY` / `ALL` / `NONE`;
 * - `AT LEAST (n)`  → at least `n` elements match.
 *
 * `$condition` is interpolated verbatim — callers build it from trusted/whitelisted
 * pieces (bound values, validated fields, …).
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\arrayContains;
 *
 * echo arrayContains( 'doc.contactPoint' , 'CURRENT.email != null' );
 * // doc.contactPoint[? FILTER CURRENT.email != null]
 *
 * echo arrayContains( 'doc.reviews' , 'CURRENT.rating >= @v' , 'AT LEAST (3)' );
 * // doc.reviews[? AT LEAST (3) FILTER CURRENT.rating >= @v]
 *
 * echo arrayContains( 'doc.flags' , 'CURRENT == true' , 'NONE' );
 * // doc.flags[? NONE FILTER CURRENT == true]
 * ```
 *
 * @param string $array      The array expression to test (e.g. `doc.contactPoint`).
 * @param string $condition  The FILTER condition tested on each element.
 * @param string $quantifier An optional quantifier (`ANY`, `ALL`, `NONE`, `AT LEAST (n)`); empty = at least one.
 *
 * @return string The formatted AQL boolean array-contains expression.
 *
 * @see arrayFilter() For the sub-array form `[* FILTER cond]`.
 * @see https://docs.arangodb.com/stable/aql/operators/#question-mark-operator
 *
 * @package oihana\arango\db\functions\arrays
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function arrayContains( string $array , string $condition , string $quantifier = Char::EMPTY ) : string
{
    $prefix = $quantifier === Char::EMPTY ? Char::EMPTY : $quantifier . Char::SPACE ;

    return $array . Char::LEFT_BRACKET . Char::QUESTION_MARK . Char::SPACE
         . $prefix . Operation::FILTER . Char::SPACE . $condition . Char::RIGHT_BRACKET ;
}
