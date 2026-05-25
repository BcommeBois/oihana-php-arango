<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `password_resets` Documents model dependency.
 *
 * Used by controllers / commands that need to persist or consume password-reset
 * request records (custom reset flow — request, accept, confirm).
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait PasswordResetsModelTrait
{
    /**
     * Initialization key for the password_resets Documents model.
     */
    public const string PASSWORD_RESETS_MODEL = 'passwordResetsModel' ;

    /**
     * The password_resets Documents model.
     */
    protected ?Documents $passwordResetsModel = null ;

    /**
     * Initializes the password_resets model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializePasswordResetsModel( array $init , ?Container $container ) :static
    {
        $this->passwordResetsModel = getDocuments( $init , $container , self::PASSWORD_RESETS_MODEL ) ;
        return $this ;
    }
}
