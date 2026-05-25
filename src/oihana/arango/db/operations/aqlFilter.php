<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Operation;
use oihana\enums\Char;
use function oihana\core\strings\predicates;

/**
 * Builds an AQL `FILTER` clause from one or more logical conditions.
 *
 * The FILTER operation restricts the results to elements that match
 * arbitrary logical conditions.
 *
 * Syntax:
 * ```aql
 * FILTER expression
 * ```
 *
 * The `expression` must evaluate to either `true` or `false`.
 *
 * Example:
 * ```php
 * use function oihana\arango\db\operations\aqlFilter;
 *
 * echo aqlFilter( 'user.age > 18' ) . PHP_EOL;
 * // FILTER user.age > 18
 *
 * echo aqlFilter( [ 'user.active == true', 'user.age >= 18' ] ) . PHP_EOL;
 * // FILTER user.active == true && user.age >= 18
 *
 * echo aqlFilter( [ 'x > 5', 'y < 10' ], '||' ) . PHP_EOL;
 * // FILTER x > 5 || y < 10
 *
 * echo aqlFilter(); // null
 * ```
 *
 * @param  string|array|null $conditions      The expression(s) to evaluate in the FILTER operation.
 * @param  string            $logicalOperator The logical operator used to join conditions if `$conditions` is an array (default `&&`).
 * @param  bool              $useParentheses  Whether to wrap the result in parentheses.
 *
 * @return ?string The compiled AQL FILTER clause, or `null` if no valid condition was provided.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/filter
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * author   Marc Alcaraz
 */
function aqlFilter
(
    string|array|null $conditions      = null ,
    string            $logicalOperator = Logic::AND ,
    bool              $useParentheses  = false

)
: ?string
{
    $conditions = is_array( $conditions )
                ? predicates( $conditions , $logicalOperator , $useParentheses )
                : $conditions ;

    return is_string( $conditions )
        ? Operation::FILTER . Char::SPACE . $conditions
        : null ;
}