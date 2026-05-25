<?php

namespace oihana\arango\auth\traits\edges;

use DI\Container;

use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\getEdges;

/**
 * Standalone trait for the `policy_has_permissions` Edges model dependency.
 *
 * @package oihana\arango\auth\traits\edges
 * @author  Marc Alcaraz
 */
trait PolicyHasPermissionsTrait
{
    /**
     * Initialization key for the policy_has_permissions Edges model.
     */
    public const string POLICY_HAS_PERMISSIONS = 'policyHasPermissions' ;

    /**
     * The policy_has_permissions Edges model.
     */
    protected ?Edges $policyHasPermissions = null ;

    /**
     * Initializes the policy_has_permissions edges dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializePolicyHasPermissions( array $init , ?Container $container ) :static
    {
        $this->policyHasPermissions = getEdges( $init , $container , self::POLICY_HAS_PERMISSIONS ) ;
        return $this ;
    }
}
