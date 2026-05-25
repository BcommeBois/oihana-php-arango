<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\Operator;
use oihana\enums\Char;

use function oihana\core\arrays\clean;
use function oihana\core\strings\compile;
use function oihana\core\strings\predicate;

/**
 * Builds a list of AQL assignments (key/value pairs) from an array.
 *
 * This is used for `COLLECT ... ASSIGN`, `COLLECT ... AGGREGATE`,
 * `FOR ... UPDATE`, `FOR ... REPLACE`, etc.
 *
 * @param array|null $assignments Array of assignments, e.g., ['key' => 'value'].
 * @param string     $separator   Separator between pairs (e.g., ", ").
 * @param string     $comparator  Comparator between key and value (e.g., " = ").
 *
 * @return string|null
 *
 * @example
 * ```php
 * $assign = ['minAge' => 'MIN(u.age)', 'maxAge' => 'MAX(u.age)'];
 * echo aqlAssignments($assign);
 * // "minAge = MIN(u.age), maxAge = MAX(u.age)"
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlAssignments
(
    ?array $assignments ,
    string $separator  = Char::COMMA . Char::SPACE,
    string $comparator = Operator::ASSIGN
)
: ?string
{
    $assignments = clean( $assignments ?? [] ) ;

    if ( empty ( $assignments ) )
    {
        return null;
    }

    $parts = [] ;
    foreach ( $assignments as $key => $value )
    {
        $parts[] = predicate( $key , $comparator , compile( $value ) ) ;
    }

    return implode( $separator , $parts ) ;
}
