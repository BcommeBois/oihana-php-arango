<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Logic;
use oihana\enums\Char;

use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\predicate;
use function oihana\core\strings\predicates;

/**
 * Assemble the AQL clauses of a `between` (range) comparison.
 *
 * Given an already-resolved left operand and the (already-resolved) lower/upper
 * bound expressions, builds the inclusive range test:
 *
 * ```aql
 * (left >= min && left <= max)   // both bounds
 *  left >= min                   // upper omitted (null)
 *  left <= max                   // lower omitted (null)
 * ```
 *
 * Bound omission is the CALLER's policy: pass `null` for a bound to drop its
 * clause. Number/string filters drop the omitted side (one-sided range); date
 * filters resolve an omitted bound to "now" upstream, so they never pass null.
 * Returns an empty string when both bounds are null.
 *
 * @param string      $left The left operand (e.g. `doc.price`, `DATE_DAY(doc.d)`).
 * @param string|null $min  The lower-bound AQL expression, or null to omit it.
 * @param string|null $max  The upper-bound AQL expression, or null to omit it.
 *
 * @return string The combined range clause (parenthesized when both bounds are present).
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function buildBetweenClauses( string $left , ?string $min , ?string $max ) : string
{
    $clauses = [] ;

    if ( $min !== null )
    {
        $clauses[] = predicate( $left , Comparator::GREATER_THAN_OR_EQUAL , $min ) ;
    }

    if ( $max !== null )
    {
        $clauses[] = predicate( $left , Comparator::LESS_THAN_OR_EQUAL , $max ) ;
    }

    if ( empty( $clauses ) )
    {
        return Char::EMPTY ;
    }

    return count( $clauses ) > 1
         ? betweenParentheses( predicates( $clauses , Logic::AND ) )
         : $clauses[ 0 ] ;
}
