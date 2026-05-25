<?php

namespace oihana\arango\models\traits\queries;

use oihana\reflect\exceptions\ConstantException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\ActiveTrait;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\FacetTrait;
use oihana\arango\models\traits\aql\FilterTrait;
use oihana\arango\models\traits\aql\SearchTrait;
use oihana\exceptions\BindException;
use oihana\models\traits\ConditionsTrait;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;

/**
 * Provides an ArangoDB query to count the number of documents in a collection.
 *
 * Supports both optimized (unfiltered) and filtered modes.
 *
 * @author Marc Alcaraz (eKameleon)
 * @since 1.0.0
 * @package oihana\arango\models\traits\queries
 */
trait CountQueryTrait
{
    use ActiveTrait ,
        ConditionsTrait ,
        BindTrait   ,
        FacetTrait  ,
        FilterTrait ,
        SearchTrait ;

    /**
     * Build the AQL query used to count documents.
     *
     * The query can be optimized (unfiltered) or fully filtered depending on `$init`.
     * Example AQL output:
     * <pre>
     * RETURN LENGTH(
     *   FOR doc IN @@collection
     *     FILTER doc.active == true && ...
     *     RETURN 1
     * )
     * </pre>
     *
     * @param array $init Initialization parameters.
     * @param array $bindVars Reference to bind variables.
     *
     * @return string The compiled AQL query string.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ConstantException
     */
    protected function buildCountQuery( array $init = [] , array &$bindVars = [] ) : string
    {
        $collection = $this->bindCollection( $bindVars ) ;
        $optimized  = $init[ Arango::OPTIMIZED ] ?? false ;

        if( $optimized === true )
        {
            // Fast count without any filters
            return aqlReturn( length( $collection ) ) ;
        }

        // Full count with filtering layers
        return aqlReturn( length
        ([
            aqlFor( [ AQL::IN => $collection ] ) ,
            aqlFilter
            ([
                ...$this->conditions ,
                $this->prepareActive ( $init , $bindVars ) ,
                $this->prepareFacets ( $init , $bindVars ) ,
                $this->prepareFilter ( $init , $bindVars ) ,
                $this->prepareSearch ( $init , $bindVars ) ,
                ...( $init[ Arango::CONDITIONS ] ?? [] )
            ]) ,
            aqlReturn( 1 )
        ])) ;
    }
}