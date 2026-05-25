<?php

namespace oihana\arango\db\helpers;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\core\strings\compile;

/**
 * Defines the basic 'INSERT' expression.
 *
 * @param array $init
 *
 * @return string
 *
 * @throws UnsupportedOperationException
 *
 * @since 1.0.0
 * @author Marc Alcaraz
 * @package oihana\arango\db\helpers
 */
function aqlInsertExpression( array $init = [] ):string
{
    $expression = $init[ AQL::INSERT ] ?? null ;

    if ( !isset( $expression ) )
    {
        throw new InvalidArgumentException( 'INSERT option is required' ) ;
    }

    return compile( [ Operation::INSERT , aqlExpression( $expression ) ] ) ;
}