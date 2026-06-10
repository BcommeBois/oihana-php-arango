<?php

namespace oihana\arango\db ;

use Closure ;
use Generator ;
use ReflectionException ;
use Throwable ;

use Psr\Log\LoggerInterface ;

use org\schema\helpers\SchemaResolver ;
use org\schema\Thing ;

use oihana\traits\ToStringTrait ;

use oihana\arango\clients\aql\AqlQuery ;
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\clients\cursor\enums\CursorField ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\options\ClientOptions ;

use oihana\arango\db\enums\ArangoConfig ;
use oihana\arango\db\enums\Extra ;
use oihana\arango\db\results\ExecutionStats ;
use oihana\arango\db\results\ExplainResult ;
use oihana\arango\db\results\ProfileResult ;
use oihana\arango\db\traits\CollectionManagementTrait ;
use oihana\arango\db\traits\ViewManagementTrait ;

/**
 * High-level façade for ArangoDB operations.
 *
 * Delegates to the `oihana\arango\clients\…` library
 * (Database / Collection / Cursor / AqlQuery). The 19 public methods
 * (10 on this class plus 9 on {@see CollectionManagementTrait}) keep
 * their signatures byte-identical so models, traits and controllers
 * consume the façade unchanged.
 *
 * @package oihana\arango\db
 */
class ArangoDB
{
    /**
     * Creates a new ArangoDB façade.
     *
     * @param array<string, mixed>  $config Configuration array, typically the `[arango]` TOML section.
     * @param LoggerInterface|null  $logger Optional PSR-3 logger.
     *
     * @throws Throwable When the underlying ArangoClient / Database cannot be built.
     */
    public function __construct( array $config = [] , ?LoggerInterface $logger = null )
    {
        try
        {
            $this->logger = $logger ;

            $options = ClientOptions::fromArray( $config ) ;

            $this->client   = new ArangoClient( $options ) ;
            $this->database = $this->client->database( $options->database ) ;
        }
        catch ( Throwable $e )
        {
            $this->logger?->error( __METHOD__ . ' failed: ' . $e->getMessage() . PHP_EOL ) ;
            throw $e ;
        }

        if ( array_key_exists( ArangoConfig::BATCH_SIZE , $config ) )
        {
            $this->batchSize = (int) $config[ ArangoConfig::BATCH_SIZE ] ;
        }

        if ( array_key_exists( ArangoConfig::MAX_RUNTIME , $config ) )
        {
            $this->maxRuntime = (float) $config[ ArangoConfig::MAX_RUNTIME ] ;
        }
    }

    use CollectionManagementTrait ,
        ToStringTrait ,
        ViewManagementTrait ;

    /**
     * @var int Default batch size for queries.
     */
    private int $batchSize = 10000 ;

    /**
     * @var ArangoClient Underlying client from the new `clients/` library.
     */
    private ArangoClient $client ;

    /**
     * @var ?Cursor Internal reference to the last executed cursor.
     */
    private ?Cursor $cursor = null ;

    /**
     * @var array<string, mixed> Query parameters and options (filled by {@see prepare()}, consumed by {@see execute()}).
     */
    private array $data ;

    /**
     * @var ?LoggerInterface Optional PSR-3 logger.
     */
    protected ?LoggerInterface $logger ;

    /**
     * @var ?float Number of seconds after which the query is automatically terminated server-side.
     */
    private ?float $maxRuntime = null ;

    // --- Auth ---

    /**
     * Authenticates against ArangoDB by posting `{username, password}` to
     * the `/_open/auth` endpoint and stores the returned JWT on the
     * underlying client for subsequent requests.
     *
     * The basic credentials are also stashed on the transport so it can
     * transparently refresh the JWT on 401 — useful for long-running
     * commands whose run time exceeds the server-side JWT TTL.
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
        return $this->client->login( $user , $password ) ;
    }

    /**
     * Switches the underlying client to Basic auth with the given
     * credentials. Subsequent requests carry
     * `Authorization: basic base64(user:password)`. Any bearer token
     * previously set is cleared.
     *
     * @param string $user
     * @param string $password
     *
     * @return void
     */
    public function useBasicAuth( string $user , string $password ) : void
    {
        $this->client->useBasicAuth( $user , $password ) ;
    }

    /**
     * Switches the underlying client to JWT/Bearer mode with the given
     * token. Subsequent requests carry `Authorization: bearer <token>`.
     *
     * Pass `null` to revert to the configured basic credentials (or to
     * anonymous mode when none are configured).
     *
     * @param string|null $token JWT to send on subsequent requests.
     *
     * @return void
     */
    public function useBearerAuth( ?string $token ) : void
    {
        $this->client->useBearerAuth( $token ) ;
    }

    // --- Metadata Getters ---

    /**
     * Returns the current Cursor reference, or null when no query has been
     * executed yet (or after {@see streamDocuments()} reset the state).
     *
     * @return ?Cursor
     */
    public function getCursor() : ?Cursor
    {
        return $this->cursor ;
    }

    /**
     * Returns the extra data attached to the last executed cursor.
     *
     * @return array<string, mixed>
     */
    public function getExtra() : array
    {
        return $this->cursor?->getExtra() ?? [] ;
    }

    /**
     * Returns the typed execution statistics of the last query, read from the
     * cursor's `extra.stats` (populated when the query ran with the `profile`
     * option, or for the parts the server always reports).
     *
     * @return ExecutionStats
     */
    public function getStats() : ExecutionStats
    {
        $stats = $this->getExtra()[ Extra::STATS ] ?? [] ;
        return new ExecutionStats( is_array( $stats ) ? $stats : [] ) ;
    }

    /**
     * Returns the typed profile of the last **profiled** query run (per-phase
     * timings + {@see ExecutionStats} + warnings), read from the cursor's `extra`.
     *
     * @return ProfileResult
     */
    public function getProfile() : ProfileResult
    {
        return new ProfileResult( $this->getExtra() ) ;
    }

    /**
     * Returns the total number of rows of the last query (when
     * `fullCount: true` was requested on the cursor options).
     *
     * @return int
     */
    public function getFoundRows() : int
    {
        return $this->cursor?->getFullCount() ?? 0 ;
    }

    // --- Query Execution & Results ---

    /**
     * Asks the optimizer for the execution plan of a query **without running it**,
     * and returns it as a typed {@see ExplainResult} (optimizer rules applied,
     * collections touched, estimated cost, and the indexes actually used).
     *
     * @param AqlQuery|string       $query    The AQL query (or an {@see AqlQuery} carrying its own binds).
     * @param array<string,mixed>   $bindVars Bind variables (omit when `$query` is an {@see AqlQuery}).
     * @param array<string,mixed>   $options  Explain options (`allPlans`, `optimizer.rules`, …).
     *
     * @return ExplainResult
     *
     * @throws ArangoException When the request fails.
     */
    public function explain( AqlQuery|string $query , array $bindVars = [] , array $options = [] ) : ExplainResult
    {
        return new ExplainResult( $this->database->explain( $query , $bindVars , $options ) ) ;
    }

    /**
     * Executes the prepared query against the server and stores the
     * resulting cursor.
     *
     * Cursor options that the server only honours under the nested
     * `options.{...}` sub-object (`fullCount`, `profile`, `stream`,
     * `maxRuntime`, `failOnWarning`, `optimizer.*`, …) are
     * automatically moved there. Root-level cursor options
     * (`count`, `batchSize`, `ttl`, `cache`, `memoryLimit`) stay at
     * the body root.
     *
     * @return static
     *
     * @throws ArangoException When the request fails.
     */
    public function execute() : static
    {
        $query    = (string) ( $this->data[ CursorField::QUERY     ] ?? '' ) ;
        $bindVars = (array)  ( $this->data[ CursorField::BIND_VARS ] ?? [] ) ;

        $root   = [] ;
        $nested = [] ;

        foreach ( $this->data as $key => $value )
        {
            if ( $key === CursorField::QUERY || $key === CursorField::BIND_VARS )
            {
                continue ;
            }
            if ( $value === null )
            {
                continue ;
            }
            if ( in_array( $key , CursorField::ROOT_OPTIONS , true ) )
            {
                $root[ $key ] = $value ;
            }
            else
            {
                $nested[ $key ] = $value ;
            }
        }

        if ( !empty( $nested ) )
        {
            $root[ CursorField::OPTIONS ] = $nested ;
        }

        $this->cursor = $this->database->query( $query , $bindVars , $root ) ;

        return $this ;
    }

    /**
     * Returns the list of documents from the last executed cursor.
     *
     * @param null|SchemaResolver|Closure|string $schema Optional class / resolver / closure to map the documents.
     *
     * @return array<int, mixed>
     *
     * @throws ArangoException
     * @throws ReflectionException
     */
    public function getDocuments( null|SchemaResolver|Closure|string $schema = null ) : array
    {
        return $this->getResult( $schema ) ?? [] ;
    }

    /**
     * Returns the first result from the last executed cursor.
     *
     * @param null|SchemaResolver|Closure|string $schema Optional class / resolver / closure to map the documents.
     *
     * @return mixed
     *
     * @throws ArangoException
     * @throws ReflectionException
     */
    public function getFirstResult( null|SchemaResolver|Closure|string $schema = null ) : mixed
    {
        $result = $this->getResult( $schema ) ;
        return $result[ 0 ] ?? null ;
    }

    /**
     * Returns the first result as an object.
     *
     * @param null|SchemaResolver|Closure|string $schema Optional class / resolver / closure to map the documents.
     *
     * @return ?object
     *
     * @throws ArangoException
     * @throws ReflectionException
     */
    public function getObject( null|SchemaResolver|Closure|string $schema = null ) : ?object
    {
        $first = $this->getFirstResult( $schema ) ;
        return is_object( $first ) ? $first : ( is_array( $first ) ? (object) $first : null ) ;
    }

    /**
     * Returns the query result as a list of hydrated documents.
     *
     * @param null|SchemaResolver|Closure|string $schema Optional class / resolver / closure to map the documents.
     *
     * @return ?array<int, mixed>
     *
     * @throws ArangoException
     * @throws ReflectionException
     */
    public function getResult( null|SchemaResolver|Closure|string $schema = null ) : ?array
    {
        if ( !isset( $this->cursor ) )
        {
            return null ;
        }

        $result = $this->cursor->all() ;

        if ( $result === [] )
        {
            return null ;
        }

        return array_map( fn( $document ) => $this->hydrateDocument( $document , $schema ) , $result ) ;
    }

    /**
     * Prepares the query data for the next call to {@see execute()}.
     *
     * Default `batchSize` and `maxRuntime` (when configured on the
     * façade) are folded in when the caller did not provide them.
     *
     * @param array<string, mixed> $data
     *
     * @return static
     */
    public function prepare( array $data = [] ) : static
    {
        $data[ CursorField::BATCH_SIZE  ] = $data[ CursorField::BATCH_SIZE  ] ?? $this->batchSize  ;
        $data[ CursorField::MAX_RUNTIME ] = $data[ CursorField::MAX_RUNTIME ] ?? $this->maxRuntime ;
        $this->data = $data ;
        return $this ;
    }

    /**
     * Returns a generator yielding documents one by one from the current cursor.
     *
     * @param null|SchemaResolver|Closure|string $schema Optional class / resolver / closure to map the documents.
     *
     * @return Generator<mixed>
     *
     * @throws ReflectionException
     */
    public function streamDocuments( null|SchemaResolver|Closure|string $schema = null ) : Generator
    {
        if ( !isset( $this->cursor ) )
        {
            return ;
        }

        try
        {
            foreach ( $this->cursor as $document )
            {
                yield $this->hydrateDocument( $document , $schema ) ;
            }
        }
        finally
        {
            $this->cursor = null ;
            $this->data   = []   ;
        }
    }

    // --- Internal Helpers ---

    /**
     * Hydrates a document according to the given schema hint.
     *
     * @param mixed                              $document The document to hydrate.
     * @param Closure|SchemaResolver|string|null $schema   Optional class / resolver / closure.
     *
     * @return mixed
     *
     * @throws ReflectionException
     */
    protected function hydrateDocument
    (
        mixed                              $document ,
        null|Closure|SchemaResolver|string $schema   = null ,
    )
    : mixed
    {
        if ( $schema instanceof Closure || $schema instanceof SchemaResolver )
        {
            $schema = $schema( $document ) ;

            if ( !is_string( $schema ) )
            {
                return is_array( $document ) ? (object) $document : $document ;
            }
        }

        if ( is_array( $document ) && is_string( $schema ) && class_exists( $schema ) )
        {
            if ( is_a( $schema , Thing::class , true ) )
            {
                return new $schema( $document ) ;
            }

            return $this->hydrate( $document , $schema ) ;
        }

        return is_array( $document ) ? (object) $document : $document ;
    }
}
