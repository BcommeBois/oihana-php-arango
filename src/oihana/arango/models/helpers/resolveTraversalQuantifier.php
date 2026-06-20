<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\Comparator;
use oihana\arango\models\enums\filters\FilterQuantifier;
use oihana\arango\models\utils\TraversalQuantifier;
use oihana\exceptions\ValidationException;

/**
 * Resolve the `quant` parameter for an edge/join traversal into the predicate
 * decisions that shape its `LENGTH( FOR … RETURN 1 ) <cmp> <threshold>` check.
 *
 * This is the relation counterpart of {@see resolveQuantifier()} (which targets
 * the array surface). It shares the same vocabulary — `any` / `none` / an integer
 * `n` — but maps it to a count comparison rather than to an AQL quantifier
 * keyword:
 * - `any` *(or absent)* → `LENGTH(...) > 0` with a `LIMIT 1` short-circuit
 *   (« at least one linked match ») — the historical, backward-compatible form;
 * - `none` → `LENGTH(...) == 0` with `LIMIT 1` (« no linked match »);
 * - a bare integer `n` (or its numeric string) → `LENGTH(...) >= n`, **without**
 *   `LIMIT` (the rows must be counted). The threshold is cast to an int and
 *   inlined, which is injection-safe — and consistent with the array surface.
 *
 * `n` means « at least n » and must be `>= 1`: « at least 0 » is always true
 * (use `none` for « no linked match »). The named quantifier `all` is handled in
 * a later increment.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\resolveTraversalQuantifier;
 *
 * resolveTraversalQuantifier( null )   ; // > 0,  LIMIT 1  (any, default)
 * resolveTraversalQuantifier( 'none' ); // == 0, LIMIT 1
 * resolveTraversalQuantifier( 3 )     ; // >= 3, no LIMIT
 * ```
 *
 * @param mixed $value The raw `quant` parameter (`any`, `none`, or an integer).
 *
 * @return TraversalQuantifier The resolved predicate decisions.
 *
 * @throws ValidationException When the quantifier is neither a known name nor an integer >= 1.
 *
 * @package oihana\arango\db\helpers
 * @since   1.4.0
 * @author  Marc Alcaraz
 */
function resolveTraversalQuantifier( mixed $value ) : TraversalQuantifier
{
    // Default / any → « at least one », existence with a LIMIT 1 short-circuit.
    if ( $value === null || $value === FilterQuantifier::ANY )
    {
        return new TraversalQuantifier( Comparator::GREATER_THAN , 0 , true , false ) ;
    }

    // none → « no linked match », absence with a LIMIT 1 short-circuit.
    if ( $value === FilterQuantifier::NONE )
    {
        return new TraversalQuantifier( Comparator::EQUAL , 0 , true , false ) ;
    }

    // Integer n (or its numeric string) → « at least n », counted (no LIMIT).
    if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) )
    {
        $n = (int) $value ;

        if ( $n < 1 )
        {
            throw new ValidationException
            (
                "Invalid traversal quantifier '$n'. An integer quantifier means " .
                "« at least n » and must be >= 1; use 'none' to match documents with no related match."
            ) ;
        }

        return new TraversalQuantifier( Comparator::GREATER_THAN_OR_EQUAL , $n , false , false ) ;
    }

    throw new ValidationException
    (
        "Invalid traversal quantifier '" . ( is_scalar( $value ) ? (string) $value : gettype( $value ) ) .
        "'. Expected one of: any, none, or an integer (at least n)."
    ) ;
}
