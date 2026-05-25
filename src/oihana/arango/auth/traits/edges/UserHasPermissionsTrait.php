<?php

namespace oihana\arango\auth\traits\edges;

use DI\Container;

use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\getEdges;

/**
 * Standalone trait for the `user_has_permissions` Edges model dependency.
 *
 * @package oihana\arango\auth\traits\edges
 * @author  Marc Alcaraz
 */
trait UserHasPermissionsTrait
{
    /**
     * Initialization key for the user_has_permissions Edges model.
     */
    public const string USER_HAS_PERMISSIONS = 'userHasPermissions' ;

    /**
     * The user_has_permissions Edges model.
     */
    protected ?Edges $userHasPermissions = null ;

    /**
     * Initializes the user_has_permissions edges dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeUserHasPermissions( array $init , ?Container $container ) :static
    {
        $this->userHasPermissions = getEdges( $init , $container , self::USER_HAS_PERMISSIONS ) ;
        return $this ;
    }
}
