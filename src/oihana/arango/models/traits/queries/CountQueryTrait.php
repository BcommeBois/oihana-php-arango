<?php

namespace oihana\arango\models\traits\queries;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
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
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function buildCountQuery( array $init = [] , array &$bindVars = [] ) : string
    {
        $optimized = $init[ Arango::OPTIMIZED ] ?? false ;

        if( $optimized === true )
        {
            // Fast count without any filters
            return aqlReturn( length( $this->bindCollection( $bindVars ) ) ) ;
        }

        // Active View search: count over the same View + SEARCH segment as the
        // list query, so list() and count() always agree (the search leaves the
        // FILTER). Otherwise the classic filtered collection count is kept.
        // The collection (or the View) is bound only on its own branch — an
        // unused bind variable would be rejected by the server.
        $viewSearch = $this->prepareViewSearch( $init , $bindVars ) ;

        $for = $viewSearch !== null
             ? aqlFor( [ AQL::IN => $this->bindView( $bindVars ) , AQL::SEARCH => $viewSearch ] )
             : aqlFor( [ AQL::IN => $this->bindCollection( $bindVars ) ] ) ;

        // Full count with filtering layers
        return aqlReturn( length
        ([
            $for ,
            aqlFilter
            ([
                ...$this->conditions ,
                $this->prepareActive ( $init , $bindVars ) ,
                $this->prepareFacets ( $init , $bindVars ) ,
                $this->prepareFilter ( $init , $bindVars ) ,
                $viewSearch === null ? $this->prepareSearch( $init , $bindVars ) : null ,
                ...( $init[ Arango::CONDITIONS ] ?? [] )
            ]) ,
            aqlReturn( 1 )
        ])) ;
    }
}