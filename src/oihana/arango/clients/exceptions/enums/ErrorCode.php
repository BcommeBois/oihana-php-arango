<?php

namespace oihana\arango\clients\exceptions\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Catalogue of ArangoDB internal error numbers that the client maps to
 * dedicated {@see \oihana\arango\clients\exceptions\ArangoException}
 * subclasses, or that callers may want to inspect directly.
 *
 * The codes are stable across ArangoDB versions and are returned in the
 * `errorNum` field of error responses (independently of the HTTP status).
 *
 * Only the codes the client actively handles are listed here. Extend this
 * class in your application if you need to recognise additional codes
 * (graphs, views, transactions, …).
 *
 * @see https://docs.arangodb.com/stable/develop/error-codes-and-meanings/
 *
 * @package oihana\arango\clients\exceptions\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ErrorCode
{
    use ConstantsTrait ;

    /**
     * The requested collection was not found.
     */
    public const int ARANGO_COLLECTION_NOT_FOUND = 1203 ;

    /**
     * Write-write conflict on the same document. Retryable after a short backoff.
     */
    public const int ARANGO_CONFLICT = 1200 ;

    /**
     * The supplied database name is invalid.
     */
    public const int ARANGO_DATABASE_NAME_INVALID = 1229 ;

    /**
     * The requested database was not found.
     */
    public const int ARANGO_DATABASE_NOT_FOUND = 1228 ;

    /**
     * The requested document was not found.
     */
    public const int ARANGO_DOCUMENT_NOT_FOUND = 1202 ;

    /**
     * Document revision mismatch (optimistic concurrency check failed).
     */
    public const int ARANGO_DOCUMENT_REV_BAD = 1218 ;

    /**
     * Duplicate name (collection, view, graph, index, analyzer).
     */
    public const int ARANGO_DUPLICATE_NAME = 1207 ;

    /**
     * Illegal name (invalid characters, reserved, or out of length range).
     */
    public const int ARANGO_ILLEGAL_NAME = 1208 ;

    /**
     * The requested index was not found.
     */
    public const int ARANGO_INDEX_NOT_FOUND = 1221 ;

    /**
     * Unique constraint violation (duplicate value on a unique index).
     */
    public const int ARANGO_UNIQUE_CONSTRAINT_VIOLATED = 1210 ;

    /**
     * Cluster backend (DBServer) is currently unavailable. Retryable after a short backoff.
     */
    public const int CLUSTER_BACKEND_UNAVAILABLE = 3002 ;

    /**
     * The operation is not allowed in the current transaction context
     * (e.g. a dirty read inside a transaction).
     */
    public const int TRANSACTION_DISALLOWED_OPERATION = 1652 ;

    /**
     * The transaction has already been aborted; further operations on
     * its handle are refused.
     */
    public const int TRANSACTION_ALREADY_ABORTED = 1658 ;

    /**
     * The transaction has already been committed; further operations on
     * its handle are refused.
     */
    public const int TRANSACTION_ALREADY_COMMITTED = 1657 ;

    /**
     * The transaction was aborted (typically by an explicit `abort()`
     * call or by an idle-timeout server-side).
     */
    public const int TRANSACTION_ABORTED = 1656 ;

    /**
     * The supplied transaction id is unknown to the server — either it
     * was never started, it has expired (TTL), or it has been terminated.
     */
    public const int TRANSACTION_NOT_FOUND = 1655 ;

    /**
     * A transaction operation timed out waiting on a lock; the
     * transaction is left in an inconsistent state and should be aborted.
     */
    public const int TRANSACTION_OPERATION_TIMEOUT = 1654 ;
}
