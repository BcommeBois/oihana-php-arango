<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\AQL;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use function oihana\arango\db\binds\aqlBind;

/**
 * Builds an AQL traversal range clause (e.g., `1..1`, `1..5`, `..2`, `3..`).
 *
 * ### Supports
 *
 * - Fixed ranges (`1..1`, `2..5`).
 * - Open-ended ranges (`1..` for "1 or more", `..3` for "up to 3").
 * - Bind parameters to avoid query plan cache invalidation.
 *
 * ### AQL Syntax
 *
 * ```aql
 * FOR v, e, p IN 1..1 OUTBOUND ...
 * FOR v, e, p IN 1..5 OUTBOUND ...
 * FOR v, e, p IN ..3 OUTBOUND ...  // 0 to 3
 * FOR v, e, p IN 2.. OUTBOUND ...  // 2 or more
 * ```
 * ### Why use bind parameters?
 *
 * Using placeholders like `@minDepth` and `@maxDepth` allows ArangoDB to reuse
 * query execution plans, improving performance for repeated queries.
 *
 * ### Examples
 * ```php
 * // Fixed range
 * echo aqlTraversalRange(1, 1);
 * // 1..1
 *
 * // Open-ended max
 * echo aqlTraversalRange(1, null);
 * // 1..
 *
 * // Open-ended min
 * echo aqlTraversalRange(null, 3);
 * // ..3
 *
 * // With bind parameters
 * $binds = [];
 * echo aqlTraversalRange(1, 5, $binds);
 * // @minDepth..@maxDepth
 * // $binds = ['minDepth' => 1, 'maxDepth' => 5]
 *
 * // With null parameters
 * echo aqlTraversalRange();
 * // ""
 * ```
 *
 * @param int|null    $minDepth     Minimum depth (inclusive). If null, no lower bound.
 * @param int|null    $maxDepth     Maximum depth (inclusive). If null, no upper bound.
 * @param array|null &$binds        Optional reference to a binds array for parameterized queries.
 * @param string      $defaultRange The default range if $minDepth=null && $maxDepth=null (Default "").
 *
 * @return string AQL traversal range (e.g., "1..1", "@minDepth..@maxDepth").
 * @throws BindException If parameter binding fails.
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlTraversalRange
(
    ?int   $minDepth     = null ,
    ?int   $maxDepth     = null ,
    ?array &$binds       = null ,
    string $defaultRange = Char::EMPTY // "1..1"
)
: string
{
    $min = $minDepth !== null
         ? (is_array( $binds ) ? aqlBind( $minDepth, $binds, AQL::MIN_DEPTH ) : (string) $minDepth )
         : null ;

    $max = $maxDepth !== null
         ? ( is_array( $binds ) ? aqlBind( $maxDepth , $binds , AQL::MAX_DEPTH ) : (string) $maxDepth )
         : null ;

    return match (true)
    {
        $min !== null && $max !== null => "$min..$max",
        $min !== null                  => "$min..",
        $max !== null                  => "..$max",
        default                        => $defaultRange ,
    };
}