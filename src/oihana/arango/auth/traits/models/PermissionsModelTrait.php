<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `permissions` Documents model dependency.
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait PermissionsModelTrait
{
    /**
     * Initialization key for the permissions Documents model.
     */
    public const string PERMISSIONS_MODEL = 'permissionsModel' ;

    /**
     * The permissions Documents model.
     */
    protected ?Documents $permissionsModel = null ;

    /**
     * Initializes the permissions model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializePermissionsModel( array $init , ?Container $container ) :static
    {
        $this->permissionsModel = getDocuments( $init , $container , self::PERMISSIONS_MODEL ) ;
        return $this ;
    }
}
