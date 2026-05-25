<?php

namespace oihana\arango\auth\traits\edges;

use DI\Container;

use oihana\arango\auth\traits\models\PoliciesModelTrait;
use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\getEdges;

/**
 * Standalone trait for the `service_has_policies` Edges model dependency.
 *
 * Extracted from {@see ZitadelServiceTrait} so any controller / trait
 * needing to traverse or mutate the service ↔ policy relation can
 * opt-in independently. Pairs naturally with {@see PoliciesModelTrait}
 * but stays composable on its own.
 *
 * @package oihana\arango\auth\traits\edges
 * @author  Marc Alcaraz
 */
trait ServiceHasPoliciesTrait
{
    /**
     * Initialization key for the service_has_policies Edges model.
     */
    public const string SERVICE_HAS_POLICIES = 'serviceHasPolicies' ;

    /**
     * The service_has_policies Edges model.
     */
    protected ?Edges $serviceHasPolicies = null ;

    /**
     * Initializes the service_has_policies edges dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeServiceHasPolicies( array $init , ?Container $container ) :static
    {
        $this->serviceHasPolicies = getEdges( $init , $container , self::SERVICE_HAS_POLICIES ) ;
        return $this ;
    }
}
