<?php

namespace oihana\arango\auth\traits\edges;

use DI\Container;

use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\getEdges;

/**
 * Standalone trait for the `service_has_permissions` Edges model dependency.
 *
 * @package oihana\arango\auth\traits\edges
 * @author  Marc Alcaraz
 */
trait ServiceHasPermissionsTrait
{
    /**
     * Initialization key for the service_has_permissions Edges model.
     */
    public const string SERVICE_HAS_PERMISSIONS = 'serviceHasPermissions' ;

    /**
     * The service_has_permissions Edges model.
     */
    protected ?Edges $serviceHasPermissions = null ;

    /**
     * Initializes the service_has_permissions edges dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeServiceHasPermissions( array $init , ?Container $container ) :static
    {
        $this->serviceHasPermissions = getEdges( $init , $container , self::SERVICE_HAS_PERMISSIONS ) ;
        return $this ;
    }
}
