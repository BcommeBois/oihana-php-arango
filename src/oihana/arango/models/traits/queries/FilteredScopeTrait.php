<?php

namespace oihana\arango\models\traits\queries;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\reflect\exceptions\ConstantException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;

use oihana\arango\db\enums\AQL;

use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;

/**
 * Builds the **filtered scope** shared by every list-family query: the `FOR`
 * segment (over the View or the collection) and the conjunctive `FILTER` clause.
 *
 * `list()`, `count()`, the facet counts and the bounds all iterate the same set
 * — so their `FOR` + `FILTER` must be built identically, or the numbers would
 * disagree (a facet bucket or a bound framing a set the list never shows). This
 * trait is the single source of that construction.
 *
 * When an active View search is present (`AQL::VIEW` declaration + a `?search`
 * term), the `FOR` iterates the View with an index-accelerated `SEARCH` segment
 * and the search leaves the `FILTER`; otherwise the classic collection `FOR`
 * with the `LIKE` sweep in the `FILTER` is kept. The collection (or the View) is
 * bound only on its own branch — an unused bind variable would be rejected by
 * the server.
 *
 * The trait assumes a host that provides the filtering fragments it composes
 * (`prepareViewSearch()`, `prepareActive()`, `prepareFacets()`, `prepareFilter()`,
 * `prepareSearch()`, `bindView()`, `bindCollection()` and the `$conditions`
 * property) — the same host contract as {@see FacetCountsQueryTrait} /
 * {@see BoundsQueryTrait}. In practice it is composed by every `*QueryTrait` of
 * the list family.
 *
 * @package oihana\arango\models\traits\queries
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait FilteredScopeTrait
{
    /**
     * Builds the `FOR` segment and the conjunctive `FILTER` clause shared by the
     * list-family queries.
     *
     * @param array $init     The query options.
     * @param array $bindVars The bind variables, populated by reference.
     *
     * @return array{0:string,1:?string} The `[ $for, $filter ]` pair.
     *
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
    protected function buildFilteredScope( array $init = [] , array &$bindVars = [] ) :array
    {
        $viewSearch = $this->prepareViewSearch( $init , $bindVars ) ;

        $for = $viewSearch !== null
             ? aqlFor( [ AQL::IN => $this->bindView( $bindVars ) , AQL::SEARCH => $viewSearch ] )
             : aqlFor( [ AQL::IN => $this->bindCollection( $bindVars ) ] ) ;

        $filter = aqlFilter
        ([
            ...$this->conditions ,
            $this->prepareActive( $init , $bindVars ) ,
            $this->prepareFacets( $init , $bindVars ) ,
            $this->prepareFilter( $init , $bindVars ) ,
            $viewSearch === null ? $this->prepareSearch( $init , $bindVars ) : null ,
            ...( $init[ AQL::CONDITIONS ] ?? [] )
        ]) ;

        return [ $for , $filter ] ;
    }
}
