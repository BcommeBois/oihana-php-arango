<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `services` Documents model dependency.
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait ServicesModelTrait
{
    /**
     * Initialization key for the services Documents model.
     */
    public const string SERVICES_MODEL = 'servicesModel' ;

    /**
     * The services Documents model.
     */
    protected ?Documents $servicesModel = null ;

    /**
     * Initializes the services model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeServicesModel( array $init , ?Container $container ) :static
    {
        $this->servicesModel = getDocuments( $init , $container , self::SERVICES_MODEL ) ;
        return $this ;
    }
}
