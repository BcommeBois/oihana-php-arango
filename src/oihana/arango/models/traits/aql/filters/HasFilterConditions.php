<?php

namespace oihana\arango\models\traits\aql\filters;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;

use function oihana\arango\db\operators\logicalNot;
use function oihana\arango\models\helpers\unreadableFilter;
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
     * Prepares the filter clause of a group : a list of conditions, led by its operator
     * (`and` when it has none).
     *
     * A group that cannot be read is REFUSED : an operator nothing knows (`xor`), a group
     * with no condition (`["or"]`), a `not` of several conditions — which used to compile
     * `(a ! b)`, AQL the server refuses. Every member is compiled by
     * {@see \oihana\arango\models\traits\aql\FilterTrait::compileFilter()}, which refuses
     * a member it cannot read instead of dropping it : dropped, it loosened an `and`.
     *
     * @param array $init The group.
     * @param array|null $binds The bind variables, populated by reference.
     * @param string $docRef The document reference the conditions are written against.
     * @param array $auth The request-level authorizer, passed down to every member.
     *
     * @return string|null The AQL condition, or `null` when no member constrains anything.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws RequestValidationException When the group, or one of its members, cannot be read.
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function prepareFilterConditions
    (
        array  $init   = [] ,
        ?array &$binds = null ,
        string $docRef = AQL::DOC ,
        array  $auth   = []
    )
    :?string
    {
        $logicalOperator = Logic::AND ;
        $operator        = null ;

        if ( is_string( $init[ 0 ] ?? null ) )
        {
            if ( !FilterLogic::includes( $init[ 0 ] ) )
            {
                throw new RequestValidationException( sprintf
                (
                    'Unknown filter group operator "%s" — expected one of: %s.' ,
                    $init[ 0 ] ,
                    implode( Char::COMMA . Char::SPACE , [ FilterLogic::AND , FilterLogic::NOT , FilterLogic::OR ] )
                )) ;
            }

            $operator        = array_shift( $init ) ;
            $logicalOperator = FilterLogic::getAlias( $operator ) ;
        }

        if ( $init === [] )
        {
            throw $operator === null
                ? unreadableFilter( $init )
                : new RequestValidationException( sprintf( 'The filter group "%s" holds no condition.' , $operator ) ) ;
        }

        if ( $operator === FilterLogic::NOT )
        {
            if ( count( $init ) !== 1 )
            {
                throw new RequestValidationException
                (
                    'The filter group "not" takes a single condition : negate several with ["not", ["and", …]] or ["not", ["or", …]].'
                ) ;
            }

            // A condition that constrains nothing (a virtual filter) has nothing to negate :
            // `!()` is AQL the server refuses.
            $condition = $this->compileFilter( $init[ 0 ] , $binds , $docRef , $auth ) ;

            return $condition === null ? null : logicalNot( $condition , true ) ;
        }

        foreach( $init as $key => $value )
        {
            $init[ $key ] = $this->compileFilter( $value , $binds , $docRef , $auth )  ;
        }

        return predicates( $init , $logicalOperator , true ) ;
    }
}
