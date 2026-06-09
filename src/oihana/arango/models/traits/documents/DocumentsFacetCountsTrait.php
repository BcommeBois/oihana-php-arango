<?php

namespace oihana\arango\models\traits\documents;

use oihana\reflect\exceptions\ConstantException;
use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\queries\FacetCountsQueryTrait;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

/**
 * Computes per-value **facet counts** for several dimensions at once, returned
 * alongside (not replacing) the document list.
 *
 * The dimensions are facet keys from `Arango::FACETS`; the buckets are counted
 * over the same conjunctive filter as `list()`. See
 * {@see FacetCountsQueryTrait} for the generated AQL.
 *
 * @package oihana\arango\models\traits\documents
 */
trait DocumentsFacetCountsTrait
{
    use ArangoTrait ,
        FacetCountsQueryTrait ;

    /**
     * Returns per-value bucket counts for the requested facet dimensions.
     *
     * @param array $init The list options. `Arango::FACET_COUNTS` holds the facet
     *                    keys to count; the other keys (`active`, `facets`,
     *                    `filter`, `search`, `conditions`) define the filtered set.
     *
     * @return array<string,array<int,object|array>> A `dimension => [ { value, count }, ... ]` map (empty when nothing countable).
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function facetCounts( array $init = [] ) :array
    {
        $bindVars = $init[ Arango::BINDS ] ?? [] ;

        $query = $this->buildFacetCountsQuery( $init , $bindVars ) ;
        if ( $query === '' )
        {
            return [] ;
        }

        $result = $this->getFirstResult( $query , $bindVars , raw: true ) ;

        return match ( true )
        {
            is_object( $result ) => get_object_vars( $result ) ,
            is_array ( $result ) => $result ,
            default              => [] ,
        } ;
    }
}
