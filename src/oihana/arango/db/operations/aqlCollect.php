<?php

namespace oihana\arango\db\operations;

use ReflectionException;

use oihana\arango\db\enums\Operator;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\options\CollectOptions;
use oihana\enums\Char;

use function oihana\arango\db\helpers\aqlAssignments;
use function oihana\core\strings\compile;

/**
 * Builds an AQL `COLLECT` clause for grouping, aggregation, and counting.
 *
 * ### Supported `$init` keys
 *
 * | Key | Type | Description |
 * |---|---|---|
 * | `AQL::ASSIGN` | array | Grouping variables, e.g., `['group' => 'doc.type']`. |
 * | `AQL::AGGREGATE` | array | Aggregation expressions, e.g., `['total' => 'SUM(doc.value)']`. |
 * | `AQL::INTO` | string | Variable name to collect documents into (e.g., `'groupDocs'`). |
 * | `AQL::PROJECTION`| string | Projection expression for the `INTO` clause (e.g., `'doc.name'`). |
 * | `AQL::KEEP` | array | An array of variable names to keep (e.g., `['var1', 'var2']`). |
 * | `AQL::WITH_COUNT`| string | Variable name for the count (e.g., `AQL::LENGTH`). |
 * | `AQL::OPTIONS` | array | `COLLECT` options (e.g., `['method' => 'sorted']`). |
 *
 * > **Note:** `AQL::AGGREGATE` and `AQL::WITH_COUNT` are mutually exclusive in AQL.
 * > When both are supplied, `AGGREGATE` takes precedence and `WITH COUNT INTO` is dropped.
 * > To count alongside other aggregates, express the count as an aggregate
 * > (e.g., `['n' => 'LENGTH(1)']`).
 *
 * ### Examples
 *
 * **Simple Count (for `countVertices`):**
 * ```php
 * echo aqlCollect([ AQL::WITH_COUNT => AQL::LENGTH ]);
 * // COLLECT WITH COUNT INTO length
 * ```
 *
 * **Grouping and Aggregating:**
 * ```php
 * echo aqlCollect
 * ([
 *     AQL::ASSIGN    => ['type' => 'doc.type'],
 *     AQL::AGGREGATE => ['count' => 'LENGTH(1)']
 * ]);
 * // COLLECT type = doc.type AGGREGATE count = LENGTH(1)
 * ```
 *
 * **Grouping with INTO:**
 * ```php
 * echo aqlCollect
 * ([
 *     AQL::ASSIGN     => ['type' => 'doc.type'],
 *     AQL::INTO       => 'items',
 *     AQL::PROJECTION => '{ name: doc.name, age: doc.age }'
 * ]);
 * // COLLECT type = doc.type INTO items = { name: doc.name, age: doc.age }
 * ```
 *
 * @param array $init Associative array of collect options.
 *
 * @return string The compiled AQL COLLECT clause, or an empty string if invalid.
 *
 * @throws ReflectionException
 *
 * @since   1.0.0
 * @author  Marc Alcaraz
 * @package oihana\arango\db\operations
 */
function aqlCollect( array $init = [] ) : string
{
    $assign     = $init[ AQL::ASSIGN     ] ?? null ;
    $aggregate  = $init[ AQL::AGGREGATE  ] ?? null ;
    $into       = $init[ AQL::INTO       ] ?? null ;
    $projection = $init[ AQL::PROJECTION ] ?? null ;
    $keep       = $init[ AQL::KEEP       ] ?? null ;
    $withCount  = $init[ AQL::WITH_COUNT ] ?? null ;

    // AGGREGATE and WITH COUNT INTO are mutually exclusive in AQL.
    // When both are provided, AGGREGATE wins and WITH COUNT INTO is dropped.
    if ( !empty( $aggregate ) )
    {
        $withCount = null ;
    }

    // A COLLECT clause must have at least one of these.
    if ( empty( $assign ) && empty( $aggregate ) && empty( $withCount ) )
    {
        return Char::EMPTY ;
    }

    $parts = [ Operation::COLLECT ];

    // 1. Assignment (Grouping) `COLLECT var = expr`
    if ( !empty( $assign ) )
    {
        $parts[] = aqlAssignments( $assign );
    }

    // 2. Aggregation `AGGREGATE var = expr`
    if ( !empty( $aggregate ) )
    {
        $parts[] = Operator::AGGREGATE  ;
        $parts[] = aqlAssignments( $aggregate );
    }

    // 3. Into `INTO var`
    if ( is_string( $into ) )
    {
        $parts[] = Operator::INTO ;
        $parts[] = $into ;

        // 4. Projection `INTO var = projection`
        if( is_string( $projection ) )
        {
            $parts[] = Operator::ASSIGN ;
            $parts[] = $projection ;
        }
    }

    // 5. Keep `KEEP var1, var2`
    if ( !empty( $keep ) )
    {
        $parts[] = Operator::KEEP;
        $parts[] = compile( $keep, Char::COMMA . Char::SPACE );
    }

    // 6. With Count `WITH COUNT INTO var`
    if ( is_string( $withCount ) && $withCount !== Char::EMPTY )
    {
        $parts[] = Operator::WITH_COUNT ;
        $parts[] = Operator::INTO ;
        $parts[] = $withCount ;
    }

    // 7. Options
    $parts[] = aqlOptions( $init , CollectOptions::class );

    return compile( $parts );
}