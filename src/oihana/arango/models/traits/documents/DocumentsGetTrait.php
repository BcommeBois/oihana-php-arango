<?php

namespace oihana\arango\models\traits\documents;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\queries\GetQueryTrait;
use oihana\exceptions\BindException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;

/**
 * Provides a method for a single document retrieval in an ArangoDB collection.
 *
 * @author Marc Alcaraz (eKameleon)
 * @since 1.0.0
 * @package oihana\arango\models\traits\queries
 */
trait DocumentsGetTrait
{
    use ArangoTrait   ,
        GetQueryTrait ;

    /**
     * Returns the specific item.
     * @param array $init The optional setting definition :
     * - value (mixed ) : The value to find in the model.
     * - key (?string) : The key attribute to target (default _key)
     * - prefix (?string) : The prefix document of the key (default use "doc" -> "doc.key" )
     * - binds : The default bind variables.
     * - conditions : The default conditions to passed-in the AQL query.
     * - extraQuery : The extra query to inject in the AQL query.
     * - active : The optional active property.
     * - fields (`?array<string>`, optional): An array of specific fields to return for each document. If not provided, all document fields are returned. Handled by `returnFields()`.</li>
     * - skin (?<string>, optional): The skin to apply on the result document.
     *
     * @return ?object The specific item.
     *
     * @throws ArangoException If there's an issue with the ArangoDB query execution.
     * @throws BindException If there's an error binding parameters to the AQL query.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException If a reflection error occurs (e.g., during internal AQL building).
     */
    public function get( array $init = [] ) :?object
    {
        $bindVars = $this->prepareBindVars( $init ) ;
        $query    = $this->buildGetQuery( $init , $bindVars ) ;
        return $this->getObject( $query ,  $bindVars ) ;
    }
}