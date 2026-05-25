<?php

namespace oihana\arango\clients\exceptions\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Field names returned by the ArangoDB server in JSON error responses.
 *
 * A typical error body looks like:
 * ```json
 * { "error": true, "code": 404, "errorNum": 1202, "errorMessage": "document not found" }
 * ```
 *
 * Use these constants when reading or building such bodies to avoid
 * magic strings sprinkled across the client code.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/general-request-handling/#error-handling
 *
 * @package oihana\arango\clients\exceptions\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ErrorField
{
    use ConstantsTrait ;

    /**
     * HTTP-equivalent status code echoed by the server in the error body.
     */
    public const string CODE = 'code' ;

    /**
     * Boolean flag set to `true` on error responses.
     */
    public const string ERROR = 'error' ;

    /**
     * Human-readable error message returned by the server.
     */
    public const string ERROR_MESSAGE = 'errorMessage' ;

    /**
     * Internal ArangoDB error number (see {@see ErrorCode}).
     */
    public const string ERROR_NUM = 'errorNum' ;
}
