<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\reflect\exceptions\ConstantException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;

use function oihana\arango\db\operators\logicalNot;
use function oihana\core\strings\predicates;

/**
 * This trait defines the conditions filter helpers. We can combine filters with logical operators.
 * @example
 * ```
 * ?filter=[ condition1 , condition2 ] // (condition1 && condition2)
 * ?filter=[ "and" , condition1 , condition2 ] // (condition1 && condition2)
 * ?filter=[ "or"  , condition1 , condition2 ] // (condition1 || condition2)
 * ?filter=[ "and" , ["or",condition1,condition2],["or",condition3,condition4]] // ( (condition1 || condition2) && (condition3 || condition4) )
 * ?filter=[ "not" , condition] // !(condition)
 * ```
 */
trait HasFilterConditions
{
    /**
     * Prepares the filter clause with a collection of conditions.
     * @param array $init
     * @param array|null $binds
     * @param string $docRef
     *
     * @return string|null
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function prepareFilterConditions
    (
        array  $init   = [] ,
        ?array &$binds = null ,
        string $docRef = AQL::DOC
    )
    :?string
    {
        $logicalOperator = Logic::AND ;
        if( FilterLogic::includes( $init[0] ) )
        {
            $operator = array_shift( $init ) ;
            if( is_string( $operator ) )
            {
                if( $operator === FilterLogic::NOT && count( $init ) == 1 )
                {
                    if( isset( $init[0] ) )
                    {
                        return logicalNot( $this->prepareFilter( $init[0] , $binds , $docRef ) , true ) ;
                    }
                    else
                    {
                        return null ;
                    }
                }
                else
                {
                    $logicalOperator = FilterLogic::getAlias( $operator ) ;
                }
            }
        }

        foreach( $init as $key => $value )
        {
            $init[ $key ] = $this->prepareFilter( $value , $binds , $docRef  )  ;
        }

        return predicates( $init , $logicalOperator , true ) ;
    }
}