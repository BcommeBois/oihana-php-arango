<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `policies` Documents model dependency.
 *
 * Extracted from {@see ZitadelServiceTrait} so any controller / trait
 * needing the policies model can opt-in independently — typically used
 * alongside an edge trait such as {@see ServiceHasPoliciesTrait} or
 * its application counterpart, but composable on its own.
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait PoliciesModelTrait
{
    /**
     * Initialization key for the policies Documents model.
     */
    public const string POLICIES_MODEL = 'policiesModel' ;

    /**
     * The policies Documents model.
     */
    protected ?Documents $policiesModel = null ;

    /**
     * Initializes the policies model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializePoliciesModel( array $init , ?Container $container ) :static
    {
        $this->policiesModel = getDocuments( $init , $container , self::POLICIES_MODEL ) ;
        return $this ;
    }
}
