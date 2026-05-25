<?php

namespace oihana\arango\db\helpers;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\core\strings\compile;

/**
 * Defines the basic 'REPLACE' expression.
 *
 * @param array $init
 *
 * @return string
 *
 * @throws UnsupportedOperationException
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlReplaceExpression( array $init = [] ):string
{
    $expression = $init[ AQL::REPLACE ] ?? null ;

    if ( !isset( $expression ) )
    {
        throw new InvalidArgumentException( 'REPLACE option is required' ) ;
    }

    return compile( [ Operation::REPLACE , aqlExpression( $expression ) ] ) ;
}