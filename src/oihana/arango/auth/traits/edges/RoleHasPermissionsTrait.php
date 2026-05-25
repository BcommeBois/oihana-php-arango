<?php

namespace oihana\arango\auth\traits\edges;

use DI\Container;

use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\getEdges;

/**
 * Standalone trait for the `role_has_permissions` Edges model dependency.
 *
 * @package oihana\arango\auth\traits\edges
 * @author  Marc Alcaraz
 */
trait RoleHasPermissionsTrait
{
    /**
     * Initialization key for the role_has_permissions Edges model.
     */
    public const string ROLE_HAS_PERMISSIONS = 'roleHasPermissions' ;

    /**
     * The role_has_permissions Edges model.
     */
    protected ?Edges $roleHasPermissions = null ;

    /**
     * Initializes the role_has_permissions edges dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeRoleHasPermissions( array $init , ?Container $container ) :static
    {
        $this->roleHasPermissions = getEdges( $init , $container , self::ROLE_HAS_PERMISSIONS ) ;
        return $this ;
    }
}
