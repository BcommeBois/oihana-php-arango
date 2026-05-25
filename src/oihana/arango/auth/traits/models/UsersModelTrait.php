<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `users` Documents model dependency.
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait UsersModelTrait
{
    /**
     * Initialization key for the users Documents model.
     */
    public const string USERS_MODEL = 'usersModel' ;

    /**
     * The users Documents model.
     */
    protected ?Documents $usersModel = null ;

    /**
     * Initializes the users model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeUsersModel( array $init , ?Container $container ) :static
    {
        $this->usersModel = getDocuments( $init , $container , self::USERS_MODEL ) ;
        return $this ;
    }
}
