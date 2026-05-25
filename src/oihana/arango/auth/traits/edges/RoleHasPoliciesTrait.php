<?php

namespace oihana\arango\auth\traits\edges;

use DI\Container;

use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\getEdges;

/**
 * Standalone trait for the `role_has_policies` Edges model dependency.
 *
 * @package oihana\arango\auth\traits\edges
 * @author  Marc Alcaraz
 */
trait RoleHasPoliciesTrait
{
    /**
     * Initialization key for the role_has_policies Edges model.
     */
    public const string ROLE_HAS_POLICIES = 'roleHasPolicies' ;

    /**
     * The role_has_policies Edges model.
     */
    protected ?Edges $roleHasPolicies = null ;

    /**
     * Initializes the role_has_policies edges dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeRoleHasPolicies( array $init , ?Container $container ) :static
    {
        $this->roleHasPolicies = getEdges( $init , $container , self::ROLE_HAS_POLICIES ) ;
        return $this ;
    }
}
