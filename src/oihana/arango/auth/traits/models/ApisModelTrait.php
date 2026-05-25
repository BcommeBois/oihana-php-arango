<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `apis` Documents model dependency.
 *
 * Used by controllers / traits that need to resolve the active API
 * registry (typically through {@see ApiResolverTrait} which composes
 * this one to add the human-identifier-to-_key resolution helpers).
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait ApisModelTrait
{
    /**
     * Initialization key for the apis Documents model.
     */
    public const string APIS_MODEL = 'apisModel' ;

    /**
     * The apis Documents model.
     */
    protected ?Documents $apisModel = null ;

    /**
     * Initializes the apis model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeApisModel( array $init , ?Container $container ) :static
    {
        $this->apisModel = getDocuments( $init , $container , self::APIS_MODEL ) ;
        return $this ;
    }
}
