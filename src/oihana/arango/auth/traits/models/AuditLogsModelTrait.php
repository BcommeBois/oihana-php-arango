<?php

namespace oihana\arango\auth\traits\models;

use DI\Container;

use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\getDocuments;

/**
 * Standalone trait for the `audit_logs` Documents model dependency.
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait AuditLogsModelTrait
{
    /**
     * Initialization key for the audit_logs Documents model.
     */
    public const string AUDIT_LOGS_MODEL = 'auditLogsModel' ;

    /**
     * The audit_logs Documents model.
     */
    protected ?Documents $auditLogsModel = null ;

    /**
     * Initializes the audit_logs model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeAuditLogsModel( array $init , ?Container $container ) :static
    {
        $this->auditLogsModel = getDocuments( $init , $container , self::AUDIT_LOGS_MODEL ) ;
        return $this ;
    }
}
