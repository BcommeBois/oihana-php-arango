<?php

namespace oihana\arango\clients\options ;

use oihana\arango\clients\enums\AuthType ;
use oihana\arango\clients\enums\ConnectionMode ;

/**
 * Typed configuration object for {@see \oihana\arango\clients\ArangoClient}.
 *
 * Replaces the legacy `ArrayAccess`-based options bag with PHP 8.4 named
 * parameters and read-only properties. Construct directly when the values
 * are known at compile time, or use {@see fromArray()} to map a free-form
 * configuration array (typically loaded from a TOML file).
 *
 * Recognised array keys (mirror of the project's `[arango]` TOML section):
 * - `database`       (string)         — target database name
 * - `endpoint`       (string)         — single endpoint URL, e.g. `tcp://127.0.0.1:8529`
 * - `endpoints`      (array<string>)  — ordered list of endpoints (failover); takes precedence over `endpoint`
 * - `authType`       (string)         — one of {@see AuthType}; defaults to {@see AuthType::BASIC}
 * - `type`           (string)         — legacy alias of `authType` (TOML compatibility)
 * - `user`           (string)         — basic auth user
 * - `password`       (string)         — basic auth password
 * - `token`          (string)         — JWT token (used when `authType = "JWT"`)
 * - `connection`     (string)         — one of {@see ConnectionMode}; defaults to {@see ConnectionMode::KEEP_ALIVE}
 * - `timeout`        (int|float)      — request timeout in seconds, legacy single knob (alias of `requestTimeout` when the latter is absent)
 * - `connectTimeout` (int|float)      — TCP / TLS handshake timeout in seconds (Guzzle `connect_timeout`)
 * - `requestTimeout` (int|float)      — full-request timeout in seconds (Guzzle `timeout`); falls back to `timeout` when omitted
 * - `maxRuntime`     (int|float|null) — server-side max runtime per query in seconds (null = unlimited)
 * - `batchSize`      (int)            — default cursor batch size
 * - `allowDirtyRead` (bool)           — opt into dirty reads (cluster only); makes the transport stamp `x-arango-allow-dirty-read: true` on every outbound request
 * - `reconnect`      (bool)           — auto-reconnect when a keep-alive connection has timed out on the server
 * - `create`         (bool)           — auto-create collections on insert when missing
 * - `debug`          (bool)           — enable verbose error logging
 *
 * Unknown keys are silently ignored.
 *
 * @package oihana\arango\clients\options
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ClientOptions
{
    /**
     * @param string|null         $database       Target database name.
     * @param array<string>       $endpoints      Ordered list of server endpoints (first one preferred; the rest are failover candidates).
     * @param string              $authType       Authentication scheme — one of {@see AuthType}.
     * @param string|null         $user           Basic auth user (when authType = BASIC).
     * @param string|null         $password       Basic auth password (when authType = BASIC).
     * @param string|null         $token          JWT token (when authType = JWT).
     * @param string              $connection     HTTP connection persistence — one of {@see ConnectionMode}.
     * @param int|float           $timeout        Legacy single-knob timeout in seconds. Aliases {@see $requestTimeout} when the latter is omitted, so existing callers keep their semantics.
     * @param int|float           $connectTimeout TCP / TLS handshake timeout in seconds (Guzzle `connect_timeout`).
     * @param int|float|null      $requestTimeout Full-request timeout in seconds (Guzzle `timeout`). When omitted, falls back to `$timeout` so a single-knob configuration keeps working unchanged.
     * @param int|float|null      $maxRuntime     Server-side max runtime per query, in seconds (null = unlimited).
     * @param int                 $batchSize      Default cursor batch size.
     * @param bool                $allowDirtyRead Opt into ArangoDB dirty reads (cluster only). When true, the transport stamps every request with the `x-arango-allow-dirty-read: true` header so reads can be served by any follower. Has no effect on single-server deployments — the server silently ignores the header.
     * @param bool                $reconnect      Auto-reconnect when a keep-alive connection has timed out on the server.
     * @param bool                $create         Auto-create collections on insert when missing.
     * @param bool                $debug          Enable verbose error logging.
     */
    public function __construct
    (
        public readonly ?string        $database       = null ,
        public readonly array          $endpoints      = [] ,
        public readonly string         $authType       = AuthType::BASIC ,
        public readonly ?string        $user           = null ,
        public readonly ?string        $password       = null ,
        public readonly ?string        $token          = null ,
        public readonly string         $connection     = ConnectionMode::KEEP_ALIVE ,
        public readonly int|float      $timeout        = self::DEFAULT_TIMEOUT ,
        public readonly int|float      $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT ,
        int|float|null                 $requestTimeout = null ,
        public readonly int|float|null $maxRuntime     = null ,
        public readonly int            $batchSize      = self::DEFAULT_BATCH_SIZE ,
        public readonly bool           $allowDirtyRead = false ,
        public readonly bool           $reconnect      = true ,
        public readonly bool           $create         = true ,
        public readonly bool           $debug          = false ,
    )
    {
        $this->requestTimeout = $requestTimeout ?? $this->timeout ;
    }

    /**
     * Full-request timeout in seconds (Guzzle `timeout`). When the caller
     * did not pass `requestTimeout` to the constructor, this value mirrors
     * {@see $timeout} so the legacy single-knob configuration keeps
     * working unchanged.
     */
    public readonly int|float $requestTimeout ;

    /**
     * Array key — opt into dirty reads on a cluster deployment
     * (`x-arango-allow-dirty-read: true` header on every request).
     */
    public const string ALLOW_DIRTY_READ = 'allowDirtyRead' ;

    /**
     * Array key — authentication scheme; expected value is one of {@see AuthType}.
     */
    public const string AUTH_TYPE = 'authType' ;

    /**
     * Array key — default cursor batch size (int).
     */
    public const string BATCH_SIZE = 'batchSize' ;

    /**
     * Array key — HTTP connection persistence mode; one of {@see ConnectionMode}.
     */
    public const string CONNECTION = 'connection' ;

    /**
     * Array key — TCP / TLS handshake timeout in seconds.
     */
    public const string CONNECT_TIMEOUT = 'connectTimeout' ;

    /**
     * Array key — when true, auto-create collections on insert if missing.
     */
    public const string CREATE = 'create' ;

    /**
     * Array key — target database name.
     */
    public const string DATABASE = 'database' ;

    /**
     * Array key — enable verbose error logging.
     */
    public const string DEBUG = 'debug' ;

    /**
     * Built-in default for {@see $batchSize} when none is provided.
     */
    public const int DEFAULT_BATCH_SIZE = 1000 ;

    /**
     * Built-in default for {@see $connectTimeout} (in seconds) when none is
     * provided. Matches Guzzle's own default and arangojs's `agentOptions`
     * connect window.
     */
    public const int DEFAULT_CONNECT_TIMEOUT = 5 ;

    /**
     * Built-in default for {@see $timeout} (in seconds) when none is provided.
     */
    public const int DEFAULT_TIMEOUT = 30 ;

    /**
     * Array key — single endpoint URL (for example `tcp://127.0.0.1:8529`).
     */
    public const string ENDPOINT = 'endpoint' ;

    /**
     * Array key — ordered list of endpoints for cluster failover; takes precedence over {@see ENDPOINT}.
     */
    public const string ENDPOINTS = 'endpoints' ;

    /**
     * Array key — server-side max runtime per query, in seconds (null = unlimited).
     */
    public const string MAX_RUNTIME = 'maxRuntime' ;

    /**
     * Array key — basic auth password (when authType = BASIC).
     */
    public const string PASSWORD = 'password' ;

    /**
     * Array key — auto-reconnect when a keep-alive connection has timed out on the server.
     */
    public const string RECONNECT = 'reconnect' ;

    /**
     * Array key — full-request timeout in seconds (Guzzle `timeout`).
     * Falls back to {@see TIMEOUT} when absent.
     */
    public const string REQUEST_TIMEOUT = 'requestTimeout' ;

    /**
     * Array key — legacy single-knob timeout in seconds. Aliases
     * {@see REQUEST_TIMEOUT} when the latter is omitted.
     */
    public const string TIMEOUT = 'timeout' ;

    /**
     * Array key — JWT token (when authType = JWT).
     */
    public const string TOKEN = 'token' ;

    /**
     * Array key — legacy alias of {@see AUTH_TYPE} kept for TOML backwards compatibility.
     */
    public const string TYPE = 'type' ;

    /**
     * Array key — basic auth user (when authType = BASIC).
     */
    public const string USER = 'user' ;

    /**
     * Returns the preferred endpoint (first entry of {@see $endpoints}) or null when none is configured.
     *
     * @return string|null
     */
    public function endpoint() : ?string
    {
        return $this->endpoints[ 0 ] ?? null ;
    }

    /**
     * Builds a ClientOptions instance from a free-form configuration array
     * (typically the `[arango]` section of a TOML file).
     *
     * Both `endpoint` (single string) and `endpoints` (array of strings) are
     * recognised; when both are present, `endpoints` wins and `endpoint` is
     * prepended only when not already in the list.
     *
     * For backwards compatibility with the legacy TOML schema, the alias key
     * `type` is accepted as a synonym for `authType`.
     *
     * @param array<string, mixed> $config
     * @return self
     */
    public static function fromArray( array $config ) : self
    {
        $endpoints = $config[ self::ENDPOINTS ] ?? [] ;
        if ( !is_array( $endpoints ) )
        {
            $endpoints = [] ;
        }

        $singleEndpoint = $config[ self::ENDPOINT ] ?? null ;
        if ( is_string( $singleEndpoint ) && $singleEndpoint !== '' && !in_array( $singleEndpoint , $endpoints , true ) )
        {
            array_unshift( $endpoints , $singleEndpoint ) ;
        }

        $authType = $config[ self::AUTH_TYPE ] ?? $config[ self::TYPE ] ?? AuthType::BASIC ;

        return new self
        (
            database       : $config[ self::DATABASE        ] ?? null ,
            endpoints      : $endpoints ,
            authType       : (string) $authType ,
            user           : $config[ self::USER            ] ?? null ,
            password       : $config[ self::PASSWORD        ] ?? null ,
            token          : $config[ self::TOKEN           ] ?? null ,
            connection     : $config[ self::CONNECTION      ] ?? ConnectionMode::KEEP_ALIVE ,
            timeout        : $config[ self::TIMEOUT         ] ?? self::DEFAULT_TIMEOUT ,
            connectTimeout : $config[ self::CONNECT_TIMEOUT ] ?? self::DEFAULT_CONNECT_TIMEOUT ,
            requestTimeout : $config[ self::REQUEST_TIMEOUT ] ?? null ,
            maxRuntime     : $config[ self::MAX_RUNTIME     ] ?? null ,
            batchSize      : $config[ self::BATCH_SIZE      ] ?? self::DEFAULT_BATCH_SIZE ,
            allowDirtyRead : (bool) ( $config[ self::ALLOW_DIRTY_READ ] ?? false ) ,
            reconnect      : (bool) ( $config[ self::RECONNECT       ] ?? true  ) ,
            create         : (bool) ( $config[ self::CREATE          ] ?? true  ) ,
            debug          : (bool) ( $config[ self::DEBUG           ] ?? false ) ,
        ) ;
    }
}
