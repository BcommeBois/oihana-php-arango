<?php

namespace oihana\arango\db\helpers;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\core\strings\compile;

/**
 * Defines a basic 'UPDATE' expression.
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
function aqlUpdateExpression( array $init = [] ):string
{
    $expression = $init[ AQL::UPDATE ] ?? null ;

    if ( !isset( $expression ) )
    {
        throw new InvalidArgumentException( 'UPDATE option is required' ) ;
    }

    return compile( [ Operation::UPDATE , aqlExpression( $expression ) ] ) ;
}
