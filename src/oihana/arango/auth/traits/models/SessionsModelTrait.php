<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `sessions` Documents model dependency.
 *
 * Used by middlewares / controllers / commands that need to read or update
 * authentication sessions (login bookkeeping, password-change revocation,
 * webhook-driven session revocation, audit logs, etc.).
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait SessionsModelTrait
{
    /**
     * Initialization key for the sessions Documents model.
     */
    public const string SESSIONS_MODEL = 'sessionsModel' ;

    /**
     * The sessions Documents model.
     */
    protected ?Documents $sessionsModel = null ;

    /**
     * Initializes the sessions model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeSessionsModel( array $init , ?Container $container ) :static
    {
        $this->sessionsModel = getDocuments( $init , $container , self::SESSIONS_MODEL ) ;
        return $this ;
    }
}
