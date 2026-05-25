<?php

namespace oihana\arango\clients\exceptions ;

use Throwable ;

use oihana\arango\clients\exceptions\enums\ErrorCode ;

/**
 * Thrown when ArangoDB reports a write-write conflict on the same document.
 *
 * The conflict typically surfaces as HTTP 409 with the internal Arango
 * code {@see ErrorCode::ARANGO_CONFLICT} (1200). Retrying the operation
 * after a short backoff is the canonical recovery path.
 *
 * @see https://docs.arangodb.com/stable/develop/error-codes-and-meanings/
 *
 * @package oihana\arango\clients\exceptions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ConflictException extends ArangoException
{
    /**
     * Creates a new ConflictException instance.
     *
     * @param string         $message    Server-provided message describing the conflict.
     * @param int            $httpStatus HTTP status returned by the server (defaults to 409).
     * @param Throwable|null $previous   Previous exception in the chain.
     */
    public function __construct
    (
        string     $message    = 'Write-write conflict on document' ,
        int        $httpStatus = 409 ,
        ?Throwable $previous   = null ,
    )
    {
        parent::__construct( $message , ErrorCode::ARANGO_CONFLICT , $httpStatus , $previous ) ;
    }

    /**
     * Conflict errors are transient: the caller should retry after a short backoff.
     *
     * @return bool Always true.
     */
    public function isSafeToRetry() : bool
    {
        return true ;
    }
}
