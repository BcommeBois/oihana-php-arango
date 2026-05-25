<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Operation;

use oihana\enums\Char;

use function oihana\core\strings\predicates;

/**
 * Builds an AQL `PRUNE` clause from one or more logical conditions.
 *
 * The PRUNE operation is used in graph traversals to stop traversing
 * along the current path if the condition is met.
 *
 * Syntax:
 * ```aql
 * PRUNE expression
 * ```
 *
 * The `expression` must evaluate to either `true` or `false`.
 *
 * Example:
 * ```php
 * use function oihana\arango\db\operations\aqlPrune;
 *
 * echo aqlPrune( 'v.age > 40' ) . PHP_EOL;
 * // PRUNE v.age > 40
 *
 * echo aqlPrune( [ 'e.type == "friend"', 'v.status == "inactive"' ], '||' ) . PHP_EOL;
 * // PRUNE e.type == "friend" || v.status == "inactive"
 *
 * echo aqlPrune(); // null
 * ```
 *
 * @param  string|array|null $conditions       The expression(s) to evaluate in the FILTER operation.
 * @param  string            $logicalOperator  The logical operator used to join conditions if `$conditions` is an array (default `&&`).
 * @return ?string                             The compiled AQL PRUNE clause, or `null` if no valid condition was provided.
 *
 * @see https://docs.arangodb.com/3.10/aql/graphs/traversals/#pruning
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * author   Marc Alcaraz
 */
function aqlPrune
(
    string|array|null $conditions      = null ,
    string            $logicalOperator = Logic::AND
)
: ?string
{
    $conditions = is_array( $conditions )
                ? predicates( $conditions , $logicalOperator )
                : $conditions ;

    return is_string( $conditions )
        ? Operation::PRUNE . Char::SPACE . $conditions
        : null ;
}