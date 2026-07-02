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
use oihana\arango\models\enums\Group;
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
     * Flattens one dimension's facet buckets into a `value => count` map.
     *
     * A thin projection over {@see DocumentsFacetCountsTrait::facetCounts()} for
     * the common "count by `<dimension>`" need: instead of the multi-dimension
     * `[ { value, count }, ... ]` bucket lists, it returns a single flat map for
     * one dimension, preserving the buckets' `count`-descending order. Malformed
     * rows (missing `value`) are skipped and each `count` is cast to `int`.
     *
     * The dimension must be a declared facet ({@see Arango::FACETS}); an unknown
     * key produces an empty map (nothing is countable). The sum of the returned
     * counts equals the number of matching documents for a **scalar** facet; for
     * a multi-valued (array-membership) facet a document contributes to several
     * buckets, so the sum over-counts — pair {@see DocumentsFacetCountsTrait::facetCounts()}
     * with a dedicated `count()` when an exact total is needed in that case.
     *
     * @param string $dimension The facet key to count.
     * @param array  $init      The filtered-set options (same keys as {@see DocumentsFacetCountsTrait::facetCounts()}).
     *
     * @return array<string,int> A `value => count` map (empty when nothing countable).
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
    public function facetCount( string $dimension , array $init = [] ) :array
    {
        $buckets = $this->facetCounts( [ ...$init , Arango::FACET_COUNTS => [ $dimension ] ] )[ $dimension ] ?? [] ;

        $counts = [] ;

        foreach ( $buckets as $row )
        {
            $value = is_object( $row ) ? ( $row->{ self::FACET_COUNT_VALUE } ?? null ) : ( $row[ self::FACET_COUNT_VALUE ] ?? null ) ;
            $count = is_object( $row ) ? ( $row->{ Group::COUNT_NAME       } ?? null ) : ( $row[ Group::COUNT_NAME       ] ?? null ) ;

            if ( $value === null )
            {
                continue ;
            }

            $counts[ $value ] = (int) $count ;
        }

        return $counts ;
    }

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
