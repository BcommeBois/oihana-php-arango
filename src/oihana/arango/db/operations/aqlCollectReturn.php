<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\AQL;
use oihana\enums\Char;

use oihana\exceptions\UnsupportedOperationException;
use function oihana\arango\db\helpers\aqlDocument;
use function oihana\core\strings\compile;

/**
 * Builds the `RETURN` clause that follows an AQL `COLLECT` produced by {@see aqlCollect()}.
 *
 * After a `COLLECT`, the iteration variable (e.g. `doc`) is out of scope: only the
 * grouping variables, the aggregate variables and the optional `WITH COUNT` variable
 * remain usable. This helper derives a valid projection from the very same `$spec`
 * given to {@see aqlCollect()}, so the two always stay in sync.
 *
 * ### Behaviour
 *
 * - An explicit, non-empty `$explicit` expression always wins (`RETURN <expr>`).
 * - Otherwise the projection is derived from the spec:
 *   - grouping keys (`array_keys(AQL::ASSIGN)`) + aggregate keys (`array_keys(AQL::AGGREGATE)`),
 *   - plus the `AQL::WITH_COUNT` variable when present.
 * - `AQL::AGGREGATE` and `AQL::WITH_COUNT` are mutually exclusive (mirrors {@see aqlCollect()}):
 *   when an aggregate is present the count variable is ignored.
 * - A pure count (no grouping, no aggregate, only `WITH_COUNT`) returns the **scalar**
 *   count (`RETURN length`), not an object.
 * - When nothing can be projected, an empty string is returned.
 *
 * `AQL::INTO` collected documents are intentionally NOT auto-projected (they may be huge);
 * pass an `$explicit` projection to expose them.
 *
 * ### Examples
 *
 * ```php
 * echo aqlCollectReturn([ AQL::ASSIGN => ['status' => 'doc.status'] ]);
 * // RETURN { status }
 *
 * echo aqlCollectReturn([ AQL::ASSIGN => ['category' => 'doc.category'], AQL::WITH_COUNT => 'count' ]);
 * // RETURN { category, count }
 *
 * echo aqlCollectReturn([ AQL::WITH_COUNT => 'length' ]);
 * // RETURN length
 *
 * echo aqlCollectReturn([ AQL::ASSIGN => ['y' => 'DATE_YEAR(doc.created)'] ], '{ year: y }');
 * // RETURN { year: y }
 * ```
 *
 * @param array $spec The same associative spec passed to {@see aqlCollect()}.
 * @param string|null $explicit An explicit RETURN expression overriding the derivation.
 *
 * @return string The compiled AQL RETURN clause, or an empty string when nothing to project.
 *
 * @throws UnsupportedOperationException
 *
 * @since   1.0.0
 * @author  Marc Alcaraz
 * @package oihana\arango\db\operations
 */
function aqlCollectReturn( array $spec = [] , ?string $explicit = null ) : string
{
    if ( is_string( $explicit ) && $explicit !== Char::EMPTY )
    {
        return aqlReturn( $explicit ) ;
    }

    $assign    = $spec[ AQL::ASSIGN     ] ?? null ;
    $aggregate = $spec[ AQL::AGGREGATE  ] ?? null ;
    $withCount = $spec[ AQL::WITH_COUNT ] ?? null ;

    $keys = [] ;

    if ( is_array( $assign ) )
    {
        $keys = [ ...$keys , ...array_keys( $assign ) ] ;
    }

    if ( is_array( $aggregate ) )
    {
        $keys = [ ...$keys , ...array_keys( $aggregate ) ] ;
    }

    // AGGREGATE and WITH COUNT INTO are mutually exclusive (see aqlCollect()).
    $countVar = ( empty( $aggregate ) && is_string( $withCount ) && $withCount !== Char::EMPTY )
              ? $withCount
              : null ;

    // Pure count, no grouping nor aggregate -> scalar count.
    if ( empty( $keys ) )
    {
        return $countVar !== null ? aqlReturn( $countVar ) : Char::EMPTY ;
    }

    if ( $countVar !== null )
    {
        $keys[] = $countVar ;
    }

    return aqlReturn( aqlDocument( compile( $keys , Char::COMMA . Char::SPACE ) ) ) ;
}
