<?php

namespace oihana\arango\clients\transaction\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Lifecycle states of a streaming transaction, reported by the
 * ArangoDB server in the `status` field of `/_api/transaction/{id}`
 * responses.
 *
 * A streaming transaction starts in {@see RUNNING} when
 * {@see \oihana\arango\clients\Database::beginTransaction()} returns.
 * It then moves to either {@see COMMITTED} (every step is durably
 * applied) or {@see ABORTED} (every step is discarded). Both terminal
 * states are final — once a transaction is committed or aborted, it
 * cannot be reused; the caller has to start a fresh one.
 *
 * The server also auto-aborts a transaction that stays idle longer
 * than its configured TTL, so a client that crashes or disconnects
 * mid-flight does not leak transactional locks indefinitely.
 *
 * @see https://docs.arangodb.com/stable/develop/transactions/stream-transactions/
 *
 * @package oihana\arango\clients\transaction\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class TransactionStatus
{
    use ConstantsTrait ;

    /**
     * The transaction has been aborted (explicitly or by the server
     * after an idle timeout). All staged writes have been discarded.
     */
    public const string ABORTED = 'aborted' ;

    /**
     * The transaction has been committed. All staged writes are
     * durably applied.
     */
    public const string COMMITTED = 'committed' ;

    /**
     * The transaction is open and accepts further steps.
     */
    public const string RUNNING = 'running' ;
}
