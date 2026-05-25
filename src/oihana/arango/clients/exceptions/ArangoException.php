<?php

namespace oihana\arango\clients\exceptions ;

use Exception ;
use Throwable ;

use oihana\arango\clients\exceptions\enums\ErrorCode ;
use oihana\arango\clients\exceptions\enums\ErrorField ;

/**
 * Base exception thrown by the ArangoDB client.
 *
 * Carries:
 * - the application-level message (`Exception::getMessage()`),
 * - the HTTP status returned by the server, exposed via `Exception::getCode()`,
 * - the ArangoDB internal error number (`errorNum`), independent of the HTTP status,
 * - a hint indicating whether the failed operation is safe to retry.
 *
 * Subclasses are expected to override {@see isSafeToRetry()} when the
 * underlying error condition is known to be transient (write-write
 * conflicts, cluster maintenance windows, throttling, …).
 *
 * @see https://docs.arangodb.com/stable/develop/error-codes-and-meanings/
 *
 * @package oihana\arango\clients\exceptions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ArangoException extends Exception
{
    /**
     * Creates a new ArangoException instance.
     *
     * @param string         $message    Human-readable message returned by the server (or constructed locally).
     * @param int|null       $errorNum   ArangoDB internal error number (for example 1200 for a write-write conflict). Null when the failure did not produce an Arango code.
     * @param int            $httpStatus HTTP status returned by the server (0 when the call never reached the server).
     * @param Throwable|null $previous   Previous exception in the chain (network failure, decoding error, …).
     */
    public function __construct
    (
        string               $message    = '' ,
        public readonly ?int $errorNum   = null ,
        int                  $httpStatus = 0 ,
        ?Throwable           $previous   = null ,
    )
    {
        parent::__construct( $message , $httpStatus , $previous ) ;
    }

    /**
     * Builds the appropriate {@see ArangoException} subclass from a parsed
     * ArangoDB error response.
     *
     * The factory inspects the `errorNum` field of the response body and
     * dispatches to a dedicated subclass when the code is known. The HTTP
     * status is preserved on the resulting exception (exposed via
     * `getCode()`).
     *
     * Expected body shape (server-provided):
     * ```
     * { "error": true, "code": 404, "errorNum": 1202, "errorMessage": "document not found" }
     * ```
     *
     * @param int            $httpStatus HTTP status returned by the server.
     * @param array          $body       Decoded JSON body (may be empty when the server returned no body).
     * @param Throwable|null $previous   Previous exception in the chain (typically the Guzzle exception).
     *
     * @return ArangoException
     */
    public static function fromResponse( int $httpStatus , array $body = [] , ?Throwable $previous = null ) : ArangoException
    {
        $errorNum = isset( $body[ ErrorField::ERROR_NUM ] ) ? (int) $body[ ErrorField::ERROR_NUM ] : null ;
        $message  = (string) ( $body[ ErrorField::ERROR_MESSAGE ] ?? 'ArangoDB error' ) ;

        return match ( $errorNum )
        {
            ErrorCode::ARANGO_CONFLICT             => new ConflictException   ( $message , $httpStatus , $previous ) ,
            ErrorCode::CLUSTER_BACKEND_UNAVAILABLE => new MaintenanceException( $message , $httpStatus , $previous ) ,
            default                                => new HttpException       ( $message , $errorNum   , $httpStatus , $previous ) ,
        } ;
    }

    /**
     * Indicates whether the failed operation is safe to retry as-is.
     *
     * The base implementation returns false. Subclasses representing
     * transient errors (conflicts, cluster maintenance, throttling, …)
     * MUST override this method to return true.
     *
     * @return bool
     */
    public function isSafeToRetry() : bool
    {
        return false ;
    }
}
