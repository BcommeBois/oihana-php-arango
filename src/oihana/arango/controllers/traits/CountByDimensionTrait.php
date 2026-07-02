<?php

namespace oihana\arango\controllers\traits;

use oihana\reflect\exceptions\ConstantException;
use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\models\Documents;
use oihana\enums\Output;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

/**
 * Aggregates a model's documents by one facet dimension into a compact
 * `{ total, counts }` payload — the controller counterpart of
 * {@see \oihana\arango\models\traits\documents\DocumentsFacetCountsTrait::facetCount()}.
 *
 * It answers the recurring "how many per `<dimension>`, in one round-trip?"
 * question (a favorites-by-type badge, a status breakdown, a per-category tally)
 * without a query per value: a single `COLLECT ... WITH COUNT` produces the
 * whole map. The dimension must be a declared facet ({@see \oihana\arango\enums\Arango::FACETS})
 * of the passed model.
 *
 * The helper takes the model and the base options explicitly, so a caller stays
 * in control of the counted set: pass an already-scoped `Arango::CONDITIONS` /
 * `Arango::BINDS` (e.g. `_from == <current user>`) and the aggregation inherits
 * it. It performs **no** access control of its own — it counts exactly the set
 * it is handed — so any permission masking belongs upstream, in the conditions.
 *
 * It returns the payload rather than an HTTP response, so it is reusable from a
 * controller (wrap it in `success()`) or a command alike.
 *
 * @package oihana\arango\controllers\traits
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait CountByDimensionTrait
{
    /**
     * The payload key holding the `value => count` map.
     */
    public const string COUNTS = 'counts' ;

    /**
     * Aggregates a model's documents by one facet dimension.
     *
     * `total` is the sum of the per-value counts — exact for a **scalar** facet
     * (each document lands in one bucket); for a multi-valued (array-membership)
     * facet a document contributes to several buckets, so the sum over-counts.
     *
     * @param Documents $model     The model to aggregate (any `Documents` / `Edges`).
     * @param string    $dimension The facet key to count (declared in {@see \oihana\arango\enums\Arango::FACETS}).
     * @param array     $init      The filtered-set options (`conditions`, `binds`, `filter`, `search`, `facets`, ...).
     *
     * @return array{total:int,counts:array<string,int>} The `{ total, counts }` payload.
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
    protected function countByDimension( Documents $model , string $dimension , array $init = [] ) :array
    {
        $counts = $model->facetCount( $dimension , $init ) ;

        return
        [
            Output::TOTAL => array_sum( $counts ) ,
            self::COUNTS  => $counts ,
        ] ;
    }
}
