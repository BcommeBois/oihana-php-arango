<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\Operation;
use oihana\arango\db\enums\Operator;

use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;

/**
 * The LET operation defines a variable within an AQL query,
 * which can then be used in subsequent expressions.
 *
 * A variable defined with LET exists only for the scope of the query or subquery.
 *
 * Syntax:
 * ```
 * LET variableName = expression
 * ```
 *
 * Example usage:
 * ```php
 * $query = let('total', 'SUM(doc.amount)');
 * // LET total = SUM(doc.amount)
 * ```
 *
 * Another examples:
 * ```php
 * $query = let('userName', "CONCAT(user.firstName, ' ', user.lastName)");
 * // LET userName = CONCAT(user.firstName, ' ', user.lastName)
 * ```
 *
 * ```php
 * $query = let( 'surface', 'doc.width * doc.height' , true );
 * // LET surface = ( doc.width * doc.height )
 * ```
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlLet
(
    string $variableName ,
    string $expression ,
    bool   $useParentheses = false  ,
    bool   $trim           = false
) :string
{
    return compile
    ([
        Operation::LET ,
        $variableName ,
        Operator::ASSIGN ,
        betweenParentheses( $expression , $useParentheses , trim: $trim ) ,
    ]) ;
}