<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `roles` Documents model dependency.
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait RolesModelTrait
{
    /**
     * Initialization key for the roles Documents model.
     */
    public const string ROLES_MODEL = 'rolesModel' ;

    /**
     * The roles Documents model.
     */
    protected ?Documents $rolesModel = null ;

    /**
     * Initializes the roles model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeRolesModel( array $init , ?Container $container ) :static
    {
        $this->rolesModel = getDocuments( $init , $container , self::ROLES_MODEL ) ;
        return $this ;
    }
}
