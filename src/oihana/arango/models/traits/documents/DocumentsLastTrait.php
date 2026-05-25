<?php

namespace oihana\arango\models\traits\documents;

use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\queries\LastQueryTrait;
use oihana\exceptions\BindException;

/**
 * Provides a method to fetch the last document from an ArangoDB collection.
 *
 * @author Marc Alcaraz (eKameleon)
 * @since 1.0.0
 * @package oihana\arango\models\traits\queries
 */
trait DocumentsLastTrait
{
    use ArangoTrait    ,
        LastQueryTrait ;

    /**
     * Returns the last document in the Documents collection.
     *
     * By default, the method search the last 'modified' document.
     *
     * @param array $init An associative array of optional settings to define the query:
     *
     * return mixed The last item in the collection (By default modified).
     * <ul>
     * <li>**`bindVars`** (`array`, optional) : Custom bind variables.</li>
     * <li>**`property`** (`string`, optional) : The property used for sorting (default: `Schema::MODIFIED`).</li>
     * <li>**`fields`** (`array<string>`, optional) : Fields to return (handled by `returnFields()`).</li>
     * </ul>
     *
     * @throws ArangoException If there's an issue with the ArangoDB query execution.
     * @throws BindException If there's an error binding parameters to the AQL query.
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException If a reflection error occurs (e.g., during internal AQL building).
     */
    public function last( array $init = [] ) :mixed
    {
        $bindVars = $init[ Arango::BINDS ] ?? [] ;
        $query    = $this->buildLastQuery( $init , $bindVars ) ;
        return $this->getFirstResult( $query , $bindVars ) ;
    }
}