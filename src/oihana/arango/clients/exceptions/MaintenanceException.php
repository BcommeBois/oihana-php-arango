<?php

namespace oihana\arango\clients\exceptions ;

use Throwable ;

use oihana\arango\clients\exceptions\enums\ErrorCode ;

/**
 * Thrown when ArangoDB reports the cluster backend is unavailable
 * (typically during maintenance windows or coordinator failovers).
 *
 * Maps to the internal Arango code {@see ErrorCode::CLUSTER_BACKEND_UNAVAILABLE}
 * (3002). The caller should retry after a short backoff.
 *
 * @see https://docs.arangodb.com/stable/develop/error-codes-and-meanings/
 *
 * @package oihana\arango\clients\exceptions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class MaintenanceException extends ArangoException
{
    /**
     * Creates a new MaintenanceException instance.
     *
     * @param string         $message    Server-provided message.
     * @param int            $httpStatus HTTP status returned by the server (defaults to 503).
     * @param Throwable|null $previous   Previous exception in the chain.
     */
    public function __construct
    (
        string     $message    = 'Cluster backend unavailable' ,
        int        $httpStatus = 503 ,
        ?Throwable $previous   = null ,
    )
    {
        parent::__construct( $message , ErrorCode::CLUSTER_BACKEND_UNAVAILABLE , $httpStatus , $previous ) ;
    }

    /**
     * Maintenance errors are transient: the caller should retry after a short backoff.
     *
     * @return bool Always true.
     */
    public function isSafeToRetry() : bool
    {
        return true ;
    }
}
