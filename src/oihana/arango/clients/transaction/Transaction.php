<?php

namespace oihana\arango\clients\transaction ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\transaction\enums\TransactionStatus ;

use function oihana\arango\clients\helpers\unwrapField ;

/**
 * Handle to a streaming transaction running on the ArangoDB server.
 *
 * Streaming transactions group N HTTP requests into a single atomic
 * unit: either every operation is durably applied (after a successful
 * {@see commit()}), or none are (after {@see abort()} or after the
 * server-side idle timeout). This handle keeps the server-assigned
 * transaction id and exposes the lifecycle operations the caller
 * needs.
 *
 * Two ways to scope operations to this transaction:
 * - call {@see step()} and run regular CRUD calls inside the
 *   callback — they automatically carry the `x-arango-trx-id`
 *   header thanks to {@see \oihana\arango\clients\http\HttpTransport::withActiveTransactionId()},
 * - pass the transaction handle's {@see $id} explicitly to
 *   {@see Database::request()} via its `$transactionId` parameter
 *   (lower level, useful for hand-rolled wire calls).
 *
 * Example — atomic two-step write:
 * ```php
 * $trx = $db->beginTransaction
 * (
 *     read  : [ 'audits' ] ,
 *     write : [ 'users' , 'audits' ] ,
 * ) ;
 *
 * try
 * {
 *     $trx->step( static function () use ( $db )
 *     {
 *         $db->collection( 'users' )->update( 'alice' , [ 'status' => 'archived' ] ) ;
 *         $db->collection( 'audits' )->insert( [ 'user' => 'alice' , 'action' => 'archive' ] ) ;
 *     } ) ;
 *     $trx->commit() ;
 * }
 * catch ( \Throwable $e )
 * {
 *     try { $trx->abort() ; } catch ( ArangoException ) {}
 *     throw $e ;
 * }
 * ```
 *
 * Or with the higher-level {@see Database::withTransaction()} helper
 * (lands in Lot 7.0c) which wraps the try/commit/abort pattern.
 *
 * @see https://docs.arangodb.com/stable/develop/transactions/stream-transactions/
 *
 * @package oihana\arango\clients\transaction
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class Transaction
{
    /**
     * @param Database $database Parent database (provides the shared HTTP transport).
     * @param string   $id       Server-assigned transaction id (as returned by `POST /_api/transaction/begin`).
     */
    public function __construct( public readonly Database $database , public readonly string $id ) {}

    /**
     * Field carrying the transaction id in the response of every
     * transaction lifecycle endpoint.
     */
    private const string ID_FIELD = 'id' ;

    /**
     * Field carrying the lifecycle status (one of {@see TransactionStatus}).
     */
    private const string STATUS_FIELD = 'status' ;

    /**
     * Wrapper of every transaction lifecycle response.
     */
    private const string RESULT_FIELD = 'result' ;

    /**
     * Aborts the transaction on the server, discarding every staged
     * write.
     *
     * After this call, the handle becomes useless — calling another
     * lifecycle method on it will surface as a `1656`
     * (`TRANSACTION_ABORTED`) or `1655` (`TRANSACTION_NOT_FOUND`)
     * error depending on how long ago the server discarded it.
     *
     * @return string The terminal status reported by the server ({@see TransactionStatus::ABORTED}).
     *
     * @throws ArangoException When the request fails.
     */
    public function abort() : string
    {
        return $this->parseStatus
        (
            $this->database->request
            (
                method : HttpMethod::DELETE ,
                path   : $this->path() ,
            )->body ,
        ) ;
    }

    /**
     * Commits the transaction on the server, durably applying every
     * staged write.
     *
     * The handle becomes useless after this call (see {@see abort()}).
     *
     * @return string The terminal status reported by the server ({@see TransactionStatus::COMMITTED}).
     *
     * @throws ArangoException When the request fails.
     */
    public function commit() : string
    {
        return $this->parseStatus
        (
            $this->database->request
            (
                method : HttpMethod::PUT ,
                path   : $this->path() ,
            )->body ,
        ) ;
    }

    /**
     * Returns true when the server still knows about this transaction.
     *
     * The endpoint returns the same payload as {@see status()} on
     * success; a `404` (or a `1655` error code) means the transaction
     * was never started, has expired, or was already terminated and
     * forgotten. Any other failure rethrows as an
     * {@see ArangoException}.
     *
     * @return bool
     *
     * @throws ArangoException When the request fails for a reason other than a 404.
     */
    public function exists() : bool
    {
        try
        {
            $this->database->request
            (
                method : HttpMethod::GET ,
                path   : $this->path() ,
            ) ;
            return true ;
        }
        catch ( HttpException $e )
        {
            if ( $e->getCode() === 404 )
            {
                return false ;
            }
            throw $e ;
        }
    }

    /**
     * Fetches the current status of the transaction on the server.
     *
     * @return string One of the {@see TransactionStatus} constants.
     *
     * @throws ArangoException When the request fails (typically a 404 when the transaction has expired).
     */
    public function status() : string
    {
        return $this->parseStatus
        (
            $this->database->request
            (
                method : HttpMethod::GET ,
                path   : $this->path() ,
            )->body ,
        ) ;
    }

    /**
     * Runs `$callback` with this transaction's id installed as the
     * active transaction scope on the underlying HTTP transport, so
     * that every CRUD call inside the callback automatically carries
     * the `x-arango-trx-id` header.
     *
     * The previous active id (typically `null`) is always restored on
     * exit, including when the callback throws — so a panicked
     * caller never leaves the transport state dangling.
     *
     * Example:
     * ```php
     * $trx->step( static function () use ( $db )
     * {
     *     $db->collection( 'users' )->insert( [ '_key' => 'alice' ] ) ;
     *     $db->collection( 'audits' )->insert( [ 'msg' => 'created alice' ] ) ;
     * } ) ;
     * ```
     *
     * @param callable(): mixed $callback User-provided block to run inside the transaction's scope.
     *
     * @return mixed The value returned by `$callback`.
     *
     * @throws \Throwable Whatever the callback throws.
     */
    public function step( callable $callback ) : mixed
    {
        return $this->database->client->transport->withActiveTransactionId( $this->id , $callback ) ;
    }

    /**
     * Builds the `/_api/transaction/{id}` path with the id URL-encoded.
     *
     * @return string
     */
    private function path() : string
    {
        return ArangoRoute::TRANSACTION . '/' . rawurlencode( $this->id ) ;
    }

    /**
     * Extracts the `status` field from a transaction lifecycle response,
     * unwrapping the outer `result` envelope when present.
     *
     * @param mixed $body Decoded response body.
     *
     * @return string One of the {@see TransactionStatus} constants, or an empty string when the field is absent (defensive — the server always emits it on success).
     */
    private function parseStatus( mixed $body ) : string
    {
        if ( !is_array( $body ) )
        {
            return '' ;
        }

        $payload = unwrapField( $body , self::RESULT_FIELD , $body ) ;
        $status  = $payload[ self::STATUS_FIELD ] ?? null ;

        return is_string( $status ) ? $status : '' ;
    }
}
