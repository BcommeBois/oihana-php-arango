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
use oihana\arango\models\traits\queries\BoundsQueryTrait;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

/**
 * Computes the numeric **bounds** — the `{ min, max }` extent — of several
 * fields at once, returned alongside (not replacing) the document list.
 *
 * The fields are bound keys from `AQL::BOUNDS`; the extent is aggregated over
 * the same conjunctive filter as `list()`. See {@see BoundsQueryTrait} for the
 * generated AQL.
 *
 * @package oihana\arango\models\traits\documents
 */
trait DocumentsBoundsTrait
{
    use ArangoTrait ,
        BoundsQueryTrait ;

    /**
     * Returns the `{ min, max }` extent of the requested bound fields.
     *
     * @param array $init The list options. `Arango::BOUNDS` holds the field keys
     *                    to bound; the other keys (`active`, `facets`, `filter`,
     *                    `search`, `conditions`) define the filtered set.
     *
     * @return array<string,array{min:mixed,max:mixed}> A `field => { min, max }` map (empty when nothing boundable).
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
    public function bounds( array $init = [] ) :array
    {
        $bindVars = $init[ Arango::BINDS ] ?? [] ;

        $query = $this->buildBoundsQuery( $init , $bindVars ) ;
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
