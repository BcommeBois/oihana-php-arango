<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\enums\Operator;
use oihana\enums\Char;

use function oihana\arango\db\helpers\aqlAssignments;
use function oihana\core\strings\compile;

/**
 * Builds an AQL `WINDOW` clause for sliding-window aggregation (running totals,
 * rolling averages, and other statistical properties over related rows).
 *
 * Two forms are supported, selected by the presence of {@see AQL::RANGE_VALUE}:
 *
 * **Row-based** (a fixed number of adjacent rows) — no `rangeValue`:
 * ```aql
 * WINDOW { preceding: numPrecedingRows, following: numFollowingRows }
 * AGGREGATE variableName = aggregateExpression
 * ```
 *
 * **Range-based** (a value or duration range around `rangeValue`) — with `rangeValue`:
 * ```aql
 * WINDOW rangeValue WITH { preceding: offsetPreceding, following: offsetFollowing }
 * AGGREGATE variableName = aggregateExpression
 * ```
 *
 * The `WITH` keyword here belongs to the range-based `WINDOW` syntax and is
 * unrelated to the collection-declaring `WITH` operation ({@see aqlWith()}).
 *
 * ### Supported `$init` keys
 *
 * | Key | Type | Description |
 * |------------------- | ---------------- | ------------------------------------------------------------------------------- |
 * | `AQL::AGGREGATE`   | array            | Aggregation expressions, e.g. `['rollingAvg' => 'AVG(doc.val)']`. **Required.** |
 * | `AQL::PRECEDING`   | int|float|string | Lower window bound (row count, value offset, or ISO 8601 duration).             |
 * | `AQL::FOLLOWING`   | int|float|string | Upper window bound (row count, value offset, or ISO 8601 duration).             |
 * | `AQL::RANGE_VALUE` | string           | The row-value expression for a range-based window (e.g. `'doc.time'`). When set, the range-based form is emitted. |
 *
 * Bound values are serialized as-is when numeric and single-quoted when given as
 * strings (so ISO 8601 durations like `PT1H` / `P1Y6M` are emitted as `'PT1H'`).
 * A bound that is `null` is omitted from the `{ … }` object.
 *
 * For a running total (aggregate every row from the start up to the current one),
 * use the string `'unbounded'` as the `preceding` bound — e.g.
 * `[ AQL::PRECEDING => 'unbounded' , AQL::FOLLOWING => 0 , … ]` yields
 * `WINDOW { preceding: 'unbounded', following: 0 } AGGREGATE …`.
 *
 * ### Examples
 *
 * **Row-based rolling average (previous, current, next row):**
 * ```php
 * echo aqlWindow
 * ([
 *     AQL::PRECEDING => 1 ,
 *     AQL::FOLLOWING => 1 ,
 *     AQL::AGGREGATE => [ 'rollingAvg' => 'AVG(doc.val)' ] ,
 * ]);
 * // WINDOW { preceding: 1, following: 1 } AGGREGATE rollingAvg = AVG(doc.val)
 * ```
 *
 * **Range-based sum over a duration window:**
 * ```php
 * echo aqlWindow
 * ([
 *     AQL::RANGE_VALUE => 'doc.time' ,
 *     AQL::PRECEDING   => 'PT1H' ,
 *     AQL::FOLLOWING   => 0 ,
 *     AQL::AGGREGATE   => [ 'total' => 'SUM(doc.val)' ] ,
 * ]);
 * // WINDOW doc.time WITH { preceding: 'PT1H', following: 0 } AGGREGATE total = SUM(doc.val)
 * ```
 *
 * @param array $init Associative array of window options.
 *
 * @return string The compiled AQL `WINDOW` clause, or an empty string when no
 *                aggregate is supplied (a `WINDOW` without aggregation is meaningless).
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/window/
 *
 * @since   1.0.0
 * @author  Marc Alcaraz
 * @package oihana\arango\db\operations
 */
function aqlWindow( array $init = [] ) :string
{
    $aggregate = $init[ AQL::AGGREGATE ] ?? null ;

    // A WINDOW clause is meaningless without at least one aggregate.
    if ( empty( $aggregate ) )
    {
        return Char::EMPTY ;
    }

    $rangeValue = $init[ AQL::RANGE_VALUE ] ?? null ;
    $preceding  = $init[ AQL::PRECEDING   ] ?? null ;
    $following  = $init[ AQL::FOLLOWING   ] ?? null ;

    $parts = [ Operation::WINDOW ] ;

    // Range-based form: WINDOW <rangeValue> WITH { ... }
    if ( is_string( $rangeValue ) && $rangeValue !== Char::EMPTY )
    {
        $parts[] = $rangeValue ;
        $parts[] = Operator::WITH ;
    }

    $parts[] = aqlWindowBounds( $preceding , $following ) ;
    $parts[] = Operator::AGGREGATE ;
    $parts[] = aqlAssignments( $aggregate ) ;

    return compile( $parts ) ;
}
