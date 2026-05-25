<?php

namespace oihana\arango\clients ;

use InvalidArgumentException ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\enums\ServerMode ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\http\HttpResponse ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\options\ClientOptions ;

use org\schema\constants\Schema;

/**
 * Entry point of the ArangoDB client.
 *
 * Holds the connection configuration ({@see ClientOptions}) and the
 * underlying {@see HttpTransport}, and exposes:
 * - server-level operations (`version()`, `listDatabases()`,
 *   `createDatabase()`, `dropDatabase()`),
 * - a factory for {@see Database} instances scoped to a specific database
 *   (`database(?string $name = null)`),
 * - a low-level passthrough (`request()`) for server-global routes that
 *   must not carry the `/_db/{name}` prefix.
 *
 * The transport is shared with every {@see Database} produced by this
 * client, so all sub-objects benefit from the same connection pool, retry
 * policy and host-ring failover.
 *
 * Example:
 * ```php
 * $client = new ArangoClient
 * (
 *     new ClientOptions
 *     (
 *         database  : 'mydb' ,
 *         endpoints : [ 'tcp://127.0.0.1:8529' ] ,
 *         user      : 'root' ,
 *         password  : 'secret' ,
 *     )
 * ) ;
 *
 * $version = $client->version() ;
 * $db      = $client->database() ; // resolves to 'mydb' from the options
 * ```
 *
 * @package oihana\arango\clients
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class ArangoClient
{
    /**
     * @param ClientOptions      $options   Connection options.
     * @param HttpTransport|null $transport Optional injectable transport (typically a transport wrapping a mocked Guzzle client in tests).
     */
    public function __construct
    (
        public ClientOptions $options ,
        ?HttpTransport       $transport = null ,
    )
    {
        $this->transport = $transport ?? new HttpTransport( $options ) ;
    }

    /**
     * Field carrying the server mode (`default` / `readonly`) in the response
     * of `GET /_admin/server/availability`.
     */
    private const string MODE_FIELD = 'mode' ;

    /**
     * Key carrying the payload in ArangoDB list responses.
     */
    private const string RESULT_FIELD = 'result' ;

    /**
     * Field carrying the timestamp in the response of `GET /_admin/time`.
     */
    private const string TIME_FIELD = 'time' ;

    /**
     * Shared HTTP transport used by this client and every {@see Database} it produces.
     */
    public HttpTransport $transport ;

    /**
     * Probes the server's availability through `GET /_admin/server/availability`.
     *
     * The endpoint returns the server's mode (one of {@see ServerMode}) on a
     * 2xx response, and a 503 status code when the server is shutting down
     * or in maintenance mode. This method surfaces the 503 branch as `false`
     * by default (the `$graceful` knob), so a caller can use it as a single
     * boolean health-check expression:
     *
     * ```php
     * if ( $client->availability() === false )
     * {
     *     // server is unreachable / in maintenance — degrade gracefully
     * }
     * ```
     *
     * Pass `$graceful: false` to let a 503 surface as an
     * {@see HttpException} alongside every other error — useful when a
     * caller wants to distinguish "down" from "unreachable network" and
     * react differently to each.
     *
     * Non-503 transport errors (404, 500, network failures, …) always
     * propagate, regardless of `$graceful`.
     *
     * @param bool $graceful When true (default), a 503 response yields `false` instead of throwing. When false, every error propagates as an {@see ArangoException}.
     *
     * @return string|false One of the {@see ServerMode} constants when the server is up, `false` when graceful and the server is in maintenance.
     *
     * @throws ArangoException When the request fails for a reason other than a 503 swallowed by `$graceful`.
     */
    public function availability( bool $graceful = true ) : string|false
    {
        try
        {
            $response = $this->request( method : HttpMethod::GET , path : ArangoRoute::ADMIN_AVAILABILITY ) ;
            $body     = is_array( $response->body ) ? $response->body : [] ;
            $mode     = $body[ self::MODE_FIELD ] ?? null ;

            return is_string( $mode ) ? $mode : false ;
        }
        catch ( HttpException $e )
        {
            if ( $graceful && $e->getCode() === 503 )
            {
                return false ;
            }
            throw $e ;
        }
    }

    /**
     * Creates a new database on the server.
     *
     * @param string $name Name of the database to create.
     *
     * @return void
     *
     * @throws ArangoException
     */
    public function createDatabase( string $name ) : void
    {
        $this->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::DATABASE ,
            body   : [ Schema::NAME => $name ] ,
        ) ;
    }

    /**
     * Returns a {@see Database} instance bound to the given name.
     *
     * When `$name` is null the database configured in
     * {@see ClientOptions::$database} is used. Throws when no database
     * name is available from either source.
     *
     * @param string|null $name
     * @return Database
     *
     * @throws InvalidArgumentException When neither `$name` nor `$options->database` is set.
     */
    public function database( ?string $name = null ) : Database
    {
        $resolved = $name ?? $this->options->database ;

        if ( $resolved === null || $resolved === '' )
        {
            throw new InvalidArgumentException
            (
                'No database name available: pass a name to ArangoClient::database() or configure ClientOptions::$database.'
            ) ;
        }

        return new Database( $this , $resolved ) ;
    }

    /**
     * Drops a database from the server.
     *
     * @param string $name Name of the database to drop.
     * @return void
     *
     * @throws ArangoException
     */
    public function dropDatabase( string $name ) : void
    {
        $this->request
        (
            method : HttpMethod::DELETE ,
            path   : ArangoRoute::DATABASE . '/' . rawurlencode( $name ) ,
        ) ;
    }

    /**
     * Returns the list of database names visible to the authenticated user.
     *
     * @return array<int, string>
     *
     * @throws ArangoException
     */
    public function listDatabases() : array
    {
        $response = $this->request( method : HttpMethod::GET , path : ArangoRoute::DATABASE ) ;
        $body     = $response->body ;

        return is_array( $body ) && is_array( $body[ self::RESULT_FIELD ] ?? null )
            ? array_values( $body[ self::RESULT_FIELD ] )
            : [] ;
    }

    /**
     * Authenticates against ArangoDB by posting `{username, password}` to
     * the `/_open/auth` endpoint and stores the returned JWT in the
     * transport for subsequent requests.
     *
     * Returns the raw JWT so the caller can forward it elsewhere (cache,
     * another client, …) if needed. The basic credentials are also stored
     * so the transport can transparently refresh the JWT on 401.
     *
     * @param string $user
     * @param string $password
     *
     * @return string The JWT returned by the server.
     *
     * @throws ArangoException When the login request fails (401, network, …).
     */
    public function login( string $user , string $password ) : string
    {
        return $this->transport->login( $user , $password ) ;
    }

    /**
     * Low-level passthrough to the underlying transport for server-global routes.
     *
     * The `/_db/{database}` URL prefix is never applied to these requests,
     * even when {@see ClientOptions::$database} is set, so the caller can
     * target global endpoints such as `/_api/version` or `/_api/database`.
     *
     * @param string $method HTTP verb.
     * @param string $path API path beginning with `/`.
     * @param array<string, mixed>|null $body Request body (JSON-encoded).
     * @param array<string, mixed> $query Query string parameters.
     * @param array<string, string> $headers Extra headers (merged with the per-request defaults).
     *
     * @return HttpResponse
     *
     * @throws ArangoException
     *
     * @see Database::request() for the database-scoped equivalent.
     */
    public function request
    (
        string $method ,
        string $path ,
        ?array $body    = null ,
        array  $query   = [] ,
        array  $headers = [] ,
    )
    : HttpResponse
    {
        return $this->transport->request
        (
            method           : $method ,
            path             : $path ,
            body             : $body ,
            query            : $query ,
            headers          : $headers ,
            databaseOverride : '' ,
        ) ;
    }

    /**
     * Returns the server's current system time as a Unix timestamp with
     * sub-second precision (the PHP `microtime(true)` convention —
     * a `float` in seconds rather than the ms tuple arangojs hands
     * back from the same endpoint).
     *
     * Wraps `GET /_admin/time`. The endpoint is server-global (it does
     * not depend on a specific database) — the client targets it
     * through its `databaseOverride: ''` path.
     *
     * Typical use cases:
     * - calibrate an application clock against the server,
     * - detect clock skew before issuing time-sensitive AQL
     *   (`DATE_NOW()` predicates, TTL index probes, …),
     * - smoke-check connectivity with a tiny payload.
     *
     * @return float Unix timestamp in seconds (with microsecond precision when the server provides it).
     *
     * @throws ArangoException When the request fails.
     */
    public function time() : float
    {
        $response = $this->request( method : HttpMethod::GET , path : ArangoRoute::ADMIN_TIME ) ;
        $body     = is_array( $response->body ) ? $response->body : [] ;

        return (float) ( $body[ self::TIME_FIELD ] ?? 0.0 ) ;
    }

    /**
     * Switches the client to Basic auth with the given credentials.
     *
     * Subsequent requests carry `Authorization: basic base64(user:password)`.
     * Any bearer token previously set is cleared. Useful in tests or admin
     * tooling that needs to switch identities at runtime.
     *
     * @param string $user
     * @param string $password
     *
     * @return void
     */
    public function useBasicAuth( string $user , string $password ) : void
    {
        $this->transport->setBasicAuth( $user , $password ) ;
    }

    /**
     * Switches the client to JWT/Bearer mode with the given token.
     *
     * Subsequent requests carry `Authorization: bearer <token>`. The
     * basic credentials (if any) are kept around so the transport can
     * refresh the JWT on 401 by re-logging in.
     *
     * Pass `null` to revert to basic credentials (or to anonymous mode
     * when none are configured).
     *
     * @param string|null $token JWT to send on subsequent requests.
     *
     * @return void
     */
    public function useBearerAuth( ?string $token ) : void
    {
        $this->transport->setBearerToken( $token ) ;
    }

    /**
     * Returns the ArangoDB server version and license information.
     *
     * @return array<string, mixed>
     *
     * @throws ArangoException
     */
    public function version() : array
    {
        $response = $this->request( method : HttpMethod::GET , path : ArangoRoute::VERSION ) ;
        $body     = $response->body ;

        return is_array( $body ) ? $body : [] ;
    }
}
