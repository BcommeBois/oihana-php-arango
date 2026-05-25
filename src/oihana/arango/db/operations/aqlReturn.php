<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\Operation;
use oihana\arango\db\enums\Operator;

use oihana\enums\Char;
use function oihana\core\strings\compile;

/**
 * Builds an AQL `RETURN` clause from a given expression.
 *
 * A RETURN operation is mandatory at the end of each AQL query block,
 * otherwise the query result would be undefined.
 * Using RETURN at the top level in data modification queries is optional.
 *
 * Example:
 * ```php
 * use function oihana\arango\db\operations\aqlReturn;
 *
 * echo aqlReturn( 'user.name' ) . PHP_EOL;
 * // RETURN user.name
 *
 * echo aqlReturn( Clause::NEW ) . PHP_EOL;
 * // RETURN NEW
 *
 * echo aqlReturn( 'user.email' , true ) . PHP_EOL;
 * // RETURN DISTINCT user.email
 * ```
 *
 * @param  mixed $expression The expression to evaluate (array or string).
 * @param  bool  $distinct   Whether to add the DISTINCT keyword in the RETURN clause.
 * @return string            The compiled AQL RETURN clause, or an empty string if expression is empty.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/return
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlReturn( mixed $expression , bool $distinct = false ):string
{
    $compiled = compile( $expression ) ;

    if ( $compiled === Char::EMPTY )
    {
        return Char::EMPTY ;
    }

    $return = Operation::RETURN ;

    if ( $distinct )
    {
        $return .= Char::SPACE . Operator::DISTINCT;
    }

    return $return . Char::SPACE . $compiled ;
}