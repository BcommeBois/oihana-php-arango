<?php

namespace oihana\arango\clients\http ;

use Throwable ;

use GuzzleHttp\Client ;
use GuzzleHttp\Exception\BadResponseException ;
use GuzzleHttp\Exception\ConnectException ;
use GuzzleHttp\Exception\GuzzleException ;

use Psr\Http\Message\ResponseInterface ;

use oihana\enums\Boolean ;
use oihana\enums\http\AuthScheme ;
use oihana\enums\http\GuzzleOption ;
use oihana\enums\http\HttpHeader ;
use oihana\files\enums\FileMimeType ;

use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\enums\AuthType ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\exceptions\NetworkException ;
use oihana\arango\clients\options\ClientOptions ;

/**
 * HTTP transport used by the ArangoDB client.
 *
 * Wraps a Guzzle {@see Client} and composes it with three policies:
 * - {@see HostRing} — round-robin failover over the configured endpoints,
 * - {@see RetryPolicy} — capped exponential back-off on transient errors,
 * - {@see ClientOptions} — base configuration (auth, timeout, connection mode,
 *   target database, …).
 *
 * The transport is intentionally low-level: it speaks JSON, returns
 * {@see HttpResponse} value objects, and never throws non-Arango
 * exceptions — every failure surfaces as a subclass of
 * {@see ArangoException}.
 *
 * The Guzzle client can be injected for testing (with a {@see \GuzzleHttp\Handler\MockHandler}).
 *
 * Runtime auth state — the transport itself is not `readonly` because
 * {@see login()}, {@see setBearerToken()} and {@see setBasicAuth()}
 * mutate the active auth scheme + credentials between requests
 * (`$current*` properties), and the 401 auto-refresh path inside
 * {@see request()} mutates them too. The injected {@see ClientOptions}
 * remains immutable; the runtime auth state is seeded from it at
 * construction time and becomes the source of truth afterwards.
 *
 * @package oihana\arango\clients\http
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class HttpTransport
{
    /**
     * @param ClientOptions    $options     Connection options (auth, timeout, endpoints, …).
     * @param Client|null      $httpClient  Optional Guzzle client; defaults to a new client built from {@see $options}. Inject a mocked client for tests.
     * @param RetryPolicy|null $retryPolicy Optional retry policy; defaults to {@see RetryPolicy} with built-in defaults.
     * @param HostRing|null    $hostRing    Optional host ring; defaults to a ring built from `$options->endpoints`.
     */
    public function __construct
    (
        public readonly ClientOptions $options ,
        ?Client                       $httpClient  = null ,
        ?RetryPolicy                  $retryPolicy = null ,
        ?HostRing                     $hostRing    = null ,
    )
    {
        $this->hostRing    = $hostRing    ?? new HostRing( $options->endpoints ) ;
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy() ;
        $this->httpClient  = $httpClient  ?? new Client( $this->guzzleConfig() ) ;

        // Seed the runtime auth state from the connection options so the
        // transport's initial behaviour is identical to the readonly past.
        // login() / setBearerToken() / setBasicAuth() mutate these slots
        // afterwards.
        $this->currentAuthType      = $options->authType ;
        $this->currentBasicPassword = $options->password ;
        $this->currentBasicUser     = $options->user ;
        $this->currentBearerToken   = $options->token ;
    }

    /**
     * Header that opts a request into ArangoDB's dirty-read mode on a
     * cluster deployment. When the transport's {@see ClientOptions::$allowDirtyRead}
     * is true, every outbound request carries this header set to
     * {@see \oihana\enums\Boolean::TRUE} so the coordinator may serve
     * reads from any follower.
     *
     * @see https://docs.arangodb.com/stable/deploy/cluster/operation/#read-from-followers
     */
    private const string ARANGO_DIRTY_READ_HEADER = 'x-arango-allow-dirty-read' ;

    /**
     * Header that scopes a request to a running streaming transaction.
     * Set by the per-request `$transactionId` parameter of {@see request()};
     * the server matches the id against an active transaction and applies
     * the operation in that transactional context.
     *
     * @see https://docs.arangodb.com/stable/develop/transactions/stream-transactions/
     */
    private const string ARANGO_TRX_ID_HEADER = 'x-arango-trx-id' ;

    /**
     * URL prefix used to scope a request to a specific database (ArangoDB convention).
     *
     * Not exposed through {@see ArangoRoute} because it is not a route —
     * it is an URL-assembly artefact that the transport prepends to
     * every database-scoped request in {@see buildUrl()}.
     *
     * @see https://docs.arangodb.com/stable/develop/http-api/databases/
     */
    private const string DATABASE_PATH_PREFIX = '/_db/' ;

    /**
     * Currently active auth scheme (`BASIC` or `JWT`). Seeded from
     * {@see ClientOptions::$authType}; mutated by {@see setBearerToken()},
     * {@see setBasicAuth()}, {@see login()} and the auto-refresh on 401.
     */
    private string $currentAuthType ;

    /**
     * Active streaming-transaction id, used as a fallback by
     * {@see request()} when no explicit `$transactionId` is passed.
     *
     * Set by {@see withActiveTransactionId()} (which always reverts the
     * previous value in a `finally` block, including on exception),
     * so this slot is never left dangling pointing at a transaction
     * that has been committed or aborted.
     *
     * The per-request `$transactionId` parameter of {@see request()}
     * still wins when both are set — the active id is only the
     * fallback for operations that don't pass one explicitly (e.g. a
     * `Collection::insert()` call invoked from inside a
     * `Transaction::step()` callback).
     */
    private ?string $activeTransactionId = null ;

    /**
     * Currently active basic-auth password. Seeded from
     * {@see ClientOptions::$password}. See {@see $currentBasicUser}.
     */
    private ?string $currentBasicPassword ;

    /**
     * Currently active basic-auth user. Seeded from
     * {@see ClientOptions::$user}; mutated by {@see setBasicAuth()}. Kept
     * around even when running in JWT mode, so the transport can refresh
     * the JWT on 401 by re-logging in.
     */
    private ?string $currentBasicUser ;

    /**
     * Currently active bearer token (JWT). Seeded from
     * {@see ClientOptions::$token}; mutated by {@see setBearerToken()},
     * {@see login()} and the auto-refresh on 401.
     */
    private ?string $currentBearerToken ;

    /**
     * Round-robin ring over the configured server endpoints.
     */
    public readonly HostRing $hostRing ;

    /**
     * Underlying Guzzle HTTP client.
     */
    private readonly Client $httpClient ;

    /**
     * Guard against re-entering the 401 refresh path from inside the
     * refresh request itself (which would loop forever).
     */
    private bool $refreshingAuth = false ;

    /**
     * Retry policy applied on transient failures.
     */
    public readonly RetryPolicy $retryPolicy ;

    /**
     * Authenticates against ArangoDB by posting `{username, password}` to
     * the unauthenticated `/_open/auth` endpoint and stores the returned
     * JWT in the transport's runtime auth state. Returns the JWT for the
     * caller's convenience (e.g. when it needs to forward it to another
     * client / cache it elsewhere).
     *
     * The basic credentials are stored alongside the JWT so the transport
     * can refresh it on 401 by re-logging in.
     *
     * @param string $user
     * @param string $password
     *
     * @return string The JWT returned by the server.
     *
     * @throws ArangoException When the request fails (network or 4xx/5xx response).
     */
    public function login( string $user , string $password ) : string
    {
        $this->refreshingAuth = true ;
        try
        {
            $response = $this->httpClient->request
            (
                'POST' ,
                $this->buildUrl( $this->hostRing->current() , ArangoRoute::OPEN_AUTH , '' ) ,
                [
                    GuzzleOption::HEADERS => [ HttpHeader::ACCEPT => FileMimeType::JSON , HttpHeader::CONTENT_TYPE => FileMimeType::JSON ] ,
                    GuzzleOption::JSON    => [ 'username' => $user , 'password' => $password ] ,
                ] ,
            ) ;

            $body = $this->decodeBody( (string) $response->getBody() ) ;
            $jwt  = is_array( $body ) && is_string( $body[ 'jwt' ] ?? null ) ? $body[ 'jwt' ] : '' ;

            if ( $jwt === '' )
            {
                throw new ArangoException( 'ArangoDB /_open/auth returned no JWT.' , null , $response->getStatusCode() ) ;
            }

            // Stash the credentials + token in the runtime state.
            $this->currentAuthType      = AuthType::JWT ;
            $this->currentBasicPassword = $password ;
            $this->currentBasicUser     = $user ;
            $this->currentBearerToken   = $jwt ;

            return $jwt ;
        }
        catch ( BadResponseException $e )
        {
            throw $this->mapBadResponse( $e ) ;
        }
        catch ( ConnectException $e )
        {
            throw new NetworkException( $e->getMessage() , null , 0 , $e ) ;
        }
        catch ( GuzzleException $e )
        {
            throw new NetworkException( $e->getMessage() , null , 0 , $e ) ;
        }
        finally
        {
            $this->refreshingAuth = false ;
        }
    }

    /**
     * Sends an HTTP request to the ArangoDB server and returns the parsed response.
     *
     * On transient failures (network errors, write-write conflict, cluster
     * maintenance, …) the request is retried according to the configured
     * {@see RetryPolicy}. When multiple endpoints are configured the ring
     * cursor is advanced between attempts.
     *
     * @param string                           $method           HTTP verb (`GET`, `POST`, …).
     * @param string                           $path             API path beginning with `/`. Prefixed with `/_db/{database}` according to `$databaseOverride`.
     * @param array<string, mixed>|string|null $body             Request body. When an array is passed the transport serialises it as JSON; when a string is passed it is sent verbatim as the raw HTTP body (use this for `/_api/import` JSON Lines payloads — caller is then responsible for the `Content-Type` header). Pass null for verbs without a body.
     * @param array<string, mixed>             $query            Query string parameters.
     * @param array<string, string>            $headers          Extra headers (merged with the per-request defaults).
     * @param string|null                      $databaseOverride Controls the `/_db/{name}` prefix:
     *                                                           - `null` (default) — use `$options->database` when set, otherwise no prefix;
     *                                                           - `''` (empty string) — no prefix (global server route, e.g. `/_api/database`);
     *                                                           - non-empty — use the given database name (overrides the options).
     * @param string|null                      $transactionId    Optional streaming transaction id to scope this request to. When non-null, the `x-arango-trx-id: {id}` header is added so the server attaches the operation to the running transaction. Passed explicitly rather than via `$headers` to keep the transaction surface typed and testable.
     *
     * @return HttpResponse
     *
     * @throws ArangoException When the request fails and the retry policy is exhausted.
     */
    public function request
    (
        string            $method ,
        string            $path ,
        array|string|null $body             = null ,
        array             $query            = [] ,
        array             $headers          = [] ,
        ?string           $databaseOverride = null ,
        ?string           $transactionId    = null ,
    )
    : HttpResponse
    {
        $effectiveTransactionId = $transactionId ?? $this->activeTransactionId ;

        if ( $effectiveTransactionId !== null )
        {
            $headers[ self::ARANGO_TRX_ID_HEADER ] = $effectiveTransactionId ;
        }

        $attempt              = 0 ;
        $authRefreshAttempted = false ;

        while ( true )
        {
            $attempt++ ;
            $endpoint = $this->hostRing->current() ;
            $url      = $this->buildUrl( $endpoint , $path , $databaseOverride ) ;

            try
            {
                $guzzleOptions = $this->mergeOptions( $body , $query , $headers ) ;
                $response      = $this->httpClient->request( $method , $url , $guzzleOptions ) ;
                return $this->mapResponse( $response ) ;
            }
            catch ( ConnectException $e )
            {
                $lastException = new NetworkException( $e->getMessage() , null , 0 , $e ) ;
            }
            catch ( BadResponseException $e )
            {
                $lastException = $this->mapBadResponse( $e ) ;
            }
            catch ( GuzzleException $e )
            {
                $lastException = new NetworkException( $e->getMessage() , null , 0 , $e ) ;
            }

            // 401 auto-refresh — when the server rejected the request because
            // the JWT has expired (or any other 401 we can recover from with a
            // fresh login) AND we have basic credentials stashed AND we are
            // not already mid-refresh AND we have not already tried a refresh
            // for the current request, then re-login and retry once. The
            // refresh itself is not counted against the retry policy budget.
            if
            (
                !$authRefreshAttempted
                && !$this->refreshingAuth
                && $lastException instanceof HttpException
                && $lastException->getCode() === 401
                && $this->currentBasicUser !== null
                && $this->currentBasicPassword !== null
            )
            {
                $authRefreshAttempted = true ;
                try
                {
                    $this->login( $this->currentBasicUser , $this->currentBasicPassword ) ;
                    continue ;
                }
                catch ( ArangoException )
                {
                    // Refresh itself failed — fall through and let the original
                    // 401 propagate so the caller sees the underlying problem.
                }
            }

            if ( !$this->retryPolicy->shouldRetry( $lastException , $attempt ) )
            {
                throw $lastException ;
            }

            if ( $this->hostRing->size() > 1 )
            {
                $this->hostRing->next() ;
            }

            $delayMs = $this->retryPolicy->delayMs( $attempt ) ;
            if ( $delayMs > 0 )
            {
                usleep( $delayMs * 1000 ) ;
            }
        }
    }

    /**
     * Switches the transport to Basic auth with the given credentials.
     *
     * Subsequent requests carry `Authorization: basic base64(user:password)`.
     * Any bearer token previously set is cleared so the basic credentials
     * take effect.
     *
     * @param string $user
     * @param string $password
     *
     * @return void
     */
    public function setBasicAuth( string $user , string $password ) : void
    {
        $this->currentAuthType      = AuthType::BASIC ;
        $this->currentBasicPassword = $password ;
        $this->currentBasicUser     = $user ;
        $this->currentBearerToken   = null ;
    }

    /**
     * Switches the transport to JWT/Bearer mode with the given token.
     *
     * Subsequent requests carry `Authorization: bearer <token>`. The basic
     * credentials (if any) are kept around so the transport can refresh
     * the JWT on 401 by re-logging in.
     *
     * Pass `null` to revert to the basic credentials (or to anonymous mode
     * when none are configured).
     *
     * @param string|null $token JWT to send on subsequent requests.
     *
     * @return void
     */
    public function setBearerToken( ?string $token ) : void
    {
        $this->currentAuthType    = $token === null ? AuthType::BASIC : AuthType::JWT ;
        $this->currentBearerToken = $token ;
    }

    /**
     * Runs `$callback` with `$id` installed as the active streaming
     * transaction id on this transport, then restores the previous
     * value (including on exception).
     *
     * While the callback runs, any {@see request()} that does not pass
     * an explicit `$transactionId` falls back to `$id` — so a
     * `Collection::insert()` (or any other plain CRUD call) invoked
     * from inside the callback is automatically scoped to the
     * running transaction. This is how {@see \oihana\arango\clients\transaction\Transaction::step()}
     * makes the transactional context transparent to existing code
     * that does not know about transactions.
     *
     * Nesting is supported: an inner `withActiveTransactionId()`
     * temporarily overrides the outer id, and reverts it on exit.
     *
     * Pass `null` for `$id` to explicitly run a callback **outside**
     * any current transaction (e.g. to make a side-channel admin call
     * that must not be part of the user's transaction).
     *
     * @param string|null         $id       Transaction id to scope the callback to (or `null` to suspend any active scope).
     * @param callable(): mixed   $callback User-provided block.
     *
     * @return mixed The value returned by `$callback`.
     *
     * @throws \Throwable Whatever the callback throws (re-thrown after the previous transaction id is restored).
     */
    public function withActiveTransactionId( ?string $id , callable $callback ) : mixed
    {
        $previous                  = $this->activeTransactionId ;
        $this->activeTransactionId = $id ;
        try
        {
            return $callback() ;
        }
        finally
        {
            $this->activeTransactionId = $previous ;
        }
    }

    /**
     * Builds the Authorization header value from the current runtime auth
     * state (which is seeded from {@see ClientOptions} at construction
     * time and mutated by {@see setBearerToken()} / {@see setBasicAuth()}
     * / {@see login()} / the auto-refresh on 401).
     *
     * @return string|null The header value (e.g. `Basic dXNlcjpwYXNz` or `Bearer eyJ…`), or null when no credentials are configured (anonymous mode).
     */
    private function buildAuthHeader() : ?string
    {
        if ( $this->currentAuthType === AuthType::JWT && $this->currentBearerToken !== null )
        {
            return AuthScheme::prefix( AuthScheme::BEARER ) . $this->currentBearerToken ;
        }
        if ( $this->currentAuthType === AuthType::BASIC && $this->currentBasicUser !== null )
        {
            $credentials = $this->currentBasicUser . ':' . ( $this->currentBasicPassword ?? '' ) ;
            return AuthScheme::prefix( AuthScheme::BASIC ) . base64_encode( $credentials ) ;
        }
        return null ;
    }

    /**
     * Builds the absolute URL for a given API path.
     *
     * The `/_db/{database}` prefix is applied based on `$databaseOverride`:
     * - `null` falls back to `$options->database`,
     * - the empty string skips the prefix entirely (global server route),
     * - any other value is used as the database name verbatim.
     *
     * @param string      $endpoint         Base endpoint URL (already normalised by {@see HostRing}).
     * @param string      $path             API path (with or without a leading `/`).
     * @param string|null $databaseOverride Optional database scope override (see above).
     *
     * @return string Absolute URL ready to be passed to the Guzzle client.
     */
    private function buildUrl( string $endpoint , string $path , ?string $databaseOverride = null ) : string
    {
        $base     = rtrim( $endpoint , '/' ) ;
        $database = $databaseOverride ?? $this->options->database ;

        if ( $database !== null && $database !== '' )
        {
            $base .= self::DATABASE_PATH_PREFIX . rawurlencode( $database ) ;
        }

        if ( $path === '' )
        {
            return $base ;
        }

        return $base . ( str_starts_with( $path , '/' ) ? $path : '/' . $path ) ;
    }

    /**
     * Decodes a JSON body string into PHP, returning null when the body is
     * empty or cannot be parsed.
     *
     * @param string $raw Raw response body.
     *
     * @return mixed Decoded value (array / scalar / null) or null on parse failure.
     */
    private function decodeBody( string $raw ) : mixed
    {
        if ( $raw === '' )
        {
            return null ;
        }
        try
        {
            return json_decode( $raw , associative : true , flags : JSON_THROW_ON_ERROR ) ;
        }
        catch ( Throwable )
        {
            return null ;
        }
    }

    /**
     * Builds the default headers attached to every request (auth + content
     * negotiation + connection mode).
     *
     * @return array<string, string>
     */
    private function defaultHeaders() : array
    {
        $headers =
        [
            HttpHeader::ACCEPT       => FileMimeType::JSON ,
            HttpHeader::CONTENT_TYPE => FileMimeType::JSON ,
            HttpHeader::CONNECTION   => $this->options->connection ,
        ] ;

        $auth = $this->buildAuthHeader() ;
        if ( $auth !== null )
        {
            $headers[ HttpHeader::AUTHORIZATION ] = $auth ;
        }

        if ( $this->options->allowDirtyRead )
        {
            $headers[ self::ARANGO_DIRTY_READ_HEADER ] = Boolean::TRUE ;
        }

        return $headers ;
    }

    /**
     * Builds the static Guzzle configuration shared across all requests.
     *
     * Default headers are intentionally applied per request (in
     * {@see mergeOptions()}) rather than at client construction time, so
     * that an externally injected {@see Client} (for instance a mocked one
     * in tests) still receives the transport's auth + content negotiation
     * headers.
     *
     * @return array<string, mixed>
     */
    private function guzzleConfig() : array
    {
        return
        [
            GuzzleOption::CONNECT_TIMEOUT => $this->options->connectTimeout ,
            GuzzleOption::TIMEOUT         => $this->options->requestTimeout ,
            GuzzleOption::HTTP_ERRORS     => true ,
        ] ;
    }

    /**
     * Wraps a Guzzle 4xx/5xx response into the appropriate ArangoException
     * subclass (Conflict / Maintenance / generic Http).
     *
     * @param BadResponseException $e The Guzzle exception raised on a 4xx/5xx response.
     *
     * @return ArangoException A typed Arango exception ready to be thrown by {@see request()}.
     */
    private function mapBadResponse( BadResponseException $e ) : ArangoException
    {
        $response = $e->getResponse() ;
        $raw      = (string) $response->getBody() ;
        $decoded  = $this->decodeBody( $raw ) ;
        $body     = is_array( $decoded ) ? $decoded : [] ;

        return ArangoException::fromResponse( $response->getStatusCode() , $body , $e ) ;
    }

    /**
     * Wraps a successful Guzzle response (2xx) into an {@see HttpResponse}
     * value object.
     *
     * @param ResponseInterface $response PSR-7 response from Guzzle.
     *
     * @return HttpResponse Decoded response carrying status, headers, body and raw payload.
     */
    private function mapResponse( ResponseInterface $response ) : HttpResponse
    {
        $raw  = (string) $response->getBody() ;
        $body = $this->decodeBody( $raw ) ;

        return new HttpResponse
        (
            status  : $response->getStatusCode() ,
            headers : $response->getHeaders() ,
            body    : $body ,
            raw     : $raw ,
        ) ;
    }

    /**
     * Merges per-request options (body, query string, headers) into the
     * shape expected by Guzzle's `request()` method.
     *
     * The body type drives the wire encoding:
     * - `array` — sent as JSON via {@see GuzzleOption::JSON} (Guzzle adds
     *   the `Content-Type: application/json` header).
     * - `string` — sent verbatim via {@see GuzzleOption::BODY} (used for
     *   `/_api/import` JSON Lines payloads; the caller is responsible
     *   for the `Content-Type` header).
     * - `null` — no body emitted.
     *
     * @param array<string, mixed>|string|null $body
     * @param array<string, mixed>             $query
     * @param array<string, string>            $headers
     *
     * @return array<string, mixed>
     */
    private function mergeOptions( array|string|null $body , array $query , array $headers ) : array
    {
        $options =
        [
            GuzzleOption::HEADERS => array_merge( $this->defaultHeaders() , $headers ) ,
        ] ;

        if ( is_string( $body ) )
        {
            $options[ GuzzleOption::BODY ] = $body ;
        }
        elseif ( $body !== null )
        {
            $options[ GuzzleOption::JSON ] = $body ;
        }
        if ( count( $query ) > 0 )
        {
            $options[ GuzzleOption::QUERY ] = $query ;
        }

        return $options ;
    }
}
