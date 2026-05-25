<?php

namespace oihana\arango\db\helpers;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\core\strings\compile;

/**
 * Defines a basic 'UPSERT' expression.
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
function aqlUpsertExpression( array $init = [] ) :string
{
    $filter = aqlFilter( $init[ AQL::FILTER ] ?? null ) ;
    $search = aqlExpression( $init[ AQL::SEARCH ] ?? null ) ;

    if( !isset( $filter ) && !isset( $search ) )
    {
        throw new InvalidArgumentException( 'Either FILTER or SEARCH option is required.' ) ;
    }
    else if( !isset( $search ) && !isset( $filter ) )
    {
        throw new InvalidArgumentException( 'FILTER and SEARCH cannot be defined at the same time.' ) ;
    }

    return compile( [ Operation::UPSERT , $search ?? $filter ] ) ;
}