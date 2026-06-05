<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\Operator;
use oihana\arango\models\enums\filters\FilterQuantifier;
use oihana\exceptions\ValidationException;

/**
 * Resolve the `quant` parameter into its AQL quantifier keyword.
 *
 * The `quant` value answers the element axis of an array filter — « how many
 * elements must satisfy the condition » — independently of the comparator (which
 * lives in `op`). Three forms are accepted:
 * - a named quantifier `any` / `all` / `none` → `ANY` / `ALL` / `NONE`
 *   (resolved through {@see FilterQuantifier::getAlias()});
 * - a bare integer `n` (or its numeric string, e.g. `3` / `"3"`) → `AT LEAST (n)`.
 *   The threshold is cast to an int and inlined, which is injection-safe.
 *
 * The returned keyword drives both array surfaces uniformly:
 * - scalar arrays via the array comparison operator (`doc.scores ALL >= @v`);
 * - object arrays via the question-mark operator (`doc.reviews[? ALL FILTER …]`).
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\resolveQuantifier;
 *
 * resolveQuantifier( 'all' ) ; // 'ALL'
 * resolveQuantifier( 'none' ); // 'NONE'
 * resolveQuantifier( 3 )     ; // 'AT LEAST (3)'
 * resolveQuantifier( '3' )   ; // 'AT LEAST (3)'
 * ```
 *
 * @param mixed $value The raw `quant` parameter (`any`/`all`/`none` or an integer).
 *
 * @return string The AQL quantifier keyword (`ANY`, `ALL`, `NONE`, `AT LEAST (n)`).
 *
 * @throws ValidationException When the quantifier is neither a known name nor an integer.
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function resolveQuantifier( mixed $value ) :string
{
    // Numeric quantifier: a bare int (or its numeric string) means « at least n ».
    if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) )
    {
        return Operator::AT_LEAST . ' (' . (int) $value . ')' ;
    }

    $keyword = FilterQuantifier::getAlias( $value ) ;

    if ( $keyword === null )
    {
        throw new ValidationException
        (
            "Invalid filter quantifier '" . ( is_scalar( $value ) ? (string) $value : gettype( $value ) ) .
            "'. Expected one of: any, all, none, or an integer (at least n)."
        ) ;
    }

    return $keyword ;
}
