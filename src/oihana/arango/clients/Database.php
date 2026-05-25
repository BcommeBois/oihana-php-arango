<?php

namespace oihana\arango\clients ;

use InvalidArgumentException ;
use Throwable ;

use oihana\enums\Boolean ;
use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\analyzer\Analyzer ;
use oihana\arango\clients\analyzer\AnalyzerOptions ;
use oihana\arango\clients\analyzer\enums\AnalyzerField ;
use oihana\arango\clients\aql\AqlQuery ;
use oihana\arango\clients\collection\Collection ;
use oihana\arango\clients\collection\EdgeCollection ;
use oihana\arango\clients\collection\enums\CollectionField ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\clients\cursor\enums\CursorField ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\graph\EdgeDefinition ;
use oihana\arango\clients\graph\Graph ;
use oihana\arango\clients\http\HttpResponse ;
use oihana\arango\clients\transaction\Transaction ;
use oihana\arango\clients\view\ArangoSearchLink ;
use oihana\arango\clients\view\View ;
use oihana\arango\clients\view\enums\ViewField ;

use function oihana\arango\clients\helpers\unwrapField ;

/**
 * Operations scoped to a specific ArangoDB database.
 *
 * Instances are obtained through {@see ArangoClient::database()} and share
 * the parent client's HTTP transport. The database name is fixed at
 * construction time and is automatically applied as a `/_db/{name}` URL
 * prefix on every {@see request()} sent through this object.
 *
 * Example:
 * ```php
 * $db = $client->database( 'mydb' ) ;
 *
 * if ( !$db->exists() )
 * {
 *     $db->create() ;
 * }
 *
 * $collections = $db->request( 'GET' , '/_api/collection' )->body ;
 * ```
 *
 * @package oihana\arango\clients
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class Database
{
    /**
     * @param ArangoClient $client Parent client (used for the shared transport and for server-level admin operations).
     * @param string       $name   Name of the target database on the server.
     */
    public function __construct( public ArangoClient $client , public string $name ) {}

    /**
     * Field carrying the collections-scope object on `POST /_api/transaction/begin`.
     */
    private const string COLLECTIONS_FIELD = 'collections' ;

    /**
     * Query parameter used by `GET /_api/collection` to skip system collections.
     */
    private const string EXCLUDE_SYSTEM_PARAM = 'excludeSystem' ;

    /**
     * Field carrying the transaction id on `/_api/transaction/*` responses.
     */
    private const string TRANSACTION_ID_FIELD = 'id' ;

    /**
     * Wrapping field of the lifecycle payload on every `/_api/transaction/*`
     * response.
     */
    private const string TRANSACTION_RESULT_FIELD = 'result' ;

    /**
     * Sub-route used to start a streaming transaction
     * (`POST /_api/transaction/begin`).
     */
    private const string TRANSACTION_BEGIN_SUFFIX = '/begin' ;

    /**
     * Returns an {@see Analyzer} instance bound to the given name in
     * this database.
     *
     * No HTTP call is made — the handle is purely client-side. Use
     * {@see createAnalyzer()} to actually create the analyzer on
     * the server, or {@see Analyzer::create()} on the returned
     * instance.
     *
     * @param string $name Analyzer name.
     *
     * @return Analyzer
     */
    public function analyzer( string $name ) : Analyzer
    {
        return new Analyzer( $this , $name ) ;
    }

    /**
     * Returns a list of {@see Analyzer} handles for every analyzer
     * currently registered on this database.
     *
     * Hits `GET /_api/analyzer`, then wraps each entry's name into
     * a fresh {@see Analyzer} instance. For the raw descriptions
     * (with `type`, `features`, `properties`), use
     * {@see listAnalyzers()}.
     *
     * Server-side built-in analyzers (`identity`, `text_en`,
     * `text_de`, …) are included in the listing — filter them out
     * on the caller side when iterating over user-defined
     * analyzers only.
     *
     * @return array<int, Analyzer>
     *
     * @throws ArangoException When the request fails.
     */
    public function analyzers() : array
    {
        $analyzers = [] ;

        foreach ( $this->listAnalyzers() as $description )
        {
            $name = $description[ AnalyzerField::NAME ] ?? null ;
            if ( is_string( $name ) && $name !== '' )
            {
                $analyzers[] = new Analyzer( $this , $name ) ;
            }
        }

        return $analyzers ;
    }

    /**
     * Starts a streaming transaction on the server and returns a
     * {@see Transaction} handle bound to its server-assigned id.
     *
     * `$write`, `$read` and `$exclusive` list the collections the
     * transaction will touch. Each can be a flat array of collection
     * names (`['users', 'audits']`) — at least one of the three must
     * be non-empty (otherwise the server rejects with
     * `transaction must contain at least one collection`).
     *
     * `$options` is a free-form array forwarded as-is to the server.
     * Recognised keys are `waitForSync`, `allowImplicit`, `lockTimeout`,
     * `maxTransactionSize`, `skipFastLockRound`, `allowDirtyRead`.
     *
     * Example:
     * ```php
     * $trx = $db->beginTransaction
     * (
     *     write : [ 'users' , 'audits' ] ,
     *     read  : [ 'audits' ] ,
     *     options : [ 'lockTimeout' => 30 , 'waitForSync' => true ] ,
     * ) ;
     *
     * try
     * {
     *     $trx->step( static fn() => $db->collection( 'users' )->insert( [ ... ] ) ) ;
     *     $trx->commit() ;
     * }
     * catch ( \Throwable $e )
     * {
     *     try { $trx->abort() ; } catch ( ArangoException ) {}
     *     throw $e ;
     * }
     * ```
     *
     * @param array<int, string>   $write     Collections written by the transaction (default: empty).
     * @param array<int, string>   $read      Collections read by the transaction (default: empty).
     * @param array<int, string>   $exclusive Collections held exclusively for the transaction (default: empty).
     * @param array<string, mixed> $options   Server-side options (`waitForSync`, `lockTimeout`, `maxTransactionSize`, `skipFastLockRound`, `allowImplicit`, `allowDirtyRead`).
     *
     * @return Transaction
     *
     * @throws ArangoException When the request fails (network or 4xx/5xx).
     */
    public function beginTransaction
    (
        array $write     = [] ,
        array $read      = [] ,
        array $exclusive = [] ,
        array $options   = [] ,
    )
    : Transaction
    {
        $collections = [] ;

        if ( $write     !== [] ) { $collections[ 'write'     ] = array_values( $write     ) ; }
        if ( $read      !== [] ) { $collections[ 'read'      ] = array_values( $read      ) ; }
        if ( $exclusive !== [] ) { $collections[ 'exclusive' ] = array_values( $exclusive ) ; }

        $body = array_merge
        (
            $options ,
            [ self::COLLECTIONS_FIELD => (object) $collections ] ,
        ) ;

        $response = $this->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::TRANSACTION . self::TRANSACTION_BEGIN_SUFFIX ,
            body   : $body ,
        ) ;

        return new Transaction( $this , $this->extractTransactionId( $response->body ) ) ;
    }

    /**
     * Returns a {@see Collection} instance bound to the given name in this database.
     *
     * The collection is created lazily on the client side — no HTTP call
     * is made by this method. The returned instance shares this
     * database's HTTP transport, so every operation on the collection
     * benefits from the same connection pool, retry policy and host-ring
     * failover.
     *
     * @param string $name Name of the target collection.
     * @return Collection
     */
    public function collection( string $name ) : Collection
    {
        return new Collection( $this , $name ) ;
    }

    /**
     * Lists the collections living in this database.
     *
     * System collections (names starting with `_`) are excluded by
     * default — pass `$includeSystem = true` to receive them too. The
     * server's metadata entries are turned into bare {@see Collection}
     * instances (sharing the same transport as this database), so the
     * caller can call CRUD methods directly on each item.
     *
     * @param bool $includeSystem Whether to include system collections in the result.
     *
     * @return array<int, Collection>
     *
     * @throws ArangoException When the request fails.
     */
    public function collections( bool $includeSystem = false ) : array
    {
        $query = $includeSystem ? [] : [ self::EXCLUDE_SYSTEM_PARAM => Boolean::TRUE ] ;

        $response = $this->request
        (
            method : HttpMethod::GET ,
            path   : ArangoRoute::COLLECTION ,
            query  : $query ,
        ) ;

        $body    = is_array( $response->body ) ? $response->body : [] ;
        $entries = is_array( $body[ CollectionField::RESULT ] ?? null ) ? $body[ CollectionField::RESULT ] : [] ;

        $collections = [] ;
        foreach ( $entries as $entry )
        {
            if ( !is_array( $entry ) || !isset( $entry[ CollectionField::NAME ] ) )
            {
                continue ;
            }
            $collections[] = $this->collection( (string) $entry[ CollectionField::NAME ] ) ;
        }

        return $collections ;
    }

    /**
     * Creates this database on the server.
     *
     * Delegates to {@see ArangoClient::createDatabase()}; the underlying
     * call is a server-global route, not scoped to {@see $name}.
     *
     * @return void
     *
     * @throws ArangoException
     */
    public function create() : void
    {
        $this->client->createDatabase( $this->name ) ;
    }

    /**
     * Creates an ArangoSearch analyzer on this database and returns
     * a fresh {@see Analyzer} handle bound to it.
     *
     * Shortcut over `$db->analyzer($name)->create($options, $features)`
     * that hits the server right away. See {@see Analyzer::create()}
     * for the accepted options and features.
     *
     * @param string             $name     Analyzer name.
     * @param AnalyzerOptions    $options  Type-specific options ({@see IdentityAnalyzer}, {@see TextAnalyzer}, {@see NormAnalyzer}, {@see StemAnalyzer}).
     * @param array<int, string> $features Optional list of analyzer features (entries of {@see \oihana\arango\clients\analyzer\enums\AnalyzerFeature}).
     *
     * @return Analyzer
     *
     * @throws ArangoException When the request fails.
     */
    public function createAnalyzer( string $name , AnalyzerOptions $options , array $features = [] ) : Analyzer
    {
        $analyzer = new Analyzer( $this , $name ) ;
        $analyzer->create( $options , $features ) ;
        return $analyzer ;
    }

    /**
     * Creates a named graph on this database and returns a fresh
     * {@see Graph} handle bound to it.
     *
     * Shortcut over `$db->graph($name)->create($edgeDefinitions, $options)`
     * that hits the server right away. See {@see Graph::create()} for
     * the recognised options.
     *
     * @param string                     $name            Graph name.
     * @param array<int, EdgeDefinition> $edgeDefinitions Edge definitions to register on creation (may be empty for a vertex-only graph).
     * @param array<string, mixed>       $options         Extra creation options forwarded verbatim to the server.
     *
     * @return Graph
     *
     * @throws ArangoException When the request fails.
     */
    public function createGraph( string $name , array $edgeDefinitions = [] , array $options = [] ) : Graph
    {
        $graph = new Graph( $this , $name ) ;
        $graph->create( $edgeDefinitions , $options ) ;
        return $graph ;
    }

    /**
     * Creates an ArangoSearch view on this database and returns a
     * fresh {@see View} handle bound to it.
     *
     * Shortcut over `$db->view($name)->create($links, $options)`
     * that hits the server right away. Only the `arangosearch`
     * view type is exposed in V1.
     *
     * @param string                                                $name    View name.
     * @param array<string, ArangoSearchLink|array<string, mixed>>  $links   Per-collection link map (each value is an {@see ArangoSearchLink} VO or its array shape).
     * @param array<string, mixed>                                  $options Extra arangosearch options forwarded verbatim (cleanupIntervalStep, consolidationIntervalMsec, primarySort, …).
     *
     * @return View
     *
     * @throws ArangoException When the request fails.
     */
    public function createView( string $name , array $links = [] , array $options = [] ) : View
    {
        $view = new View( $this , $name ) ;
        $view->create( $links , $options ) ;
        return $view ;
    }

    /**
     * Drops this database from the server.
     *
     * Delegates to {@see ArangoClient::dropDatabase()}; the underlying
     * call is a server-global route, not scoped to {@see $name}.
     *
     * @return void
     *
     * @throws ArangoException
     */
    public function drop() : void
    {
        $this->client->dropDatabase( $this->name ) ;
    }

    /**
     * Returns an {@see EdgeCollection} instance bound to the given name
     * in this database.
     *
     * Identical to {@see collection()} on the client side (no HTTP call
     * is made by this method) — the returned instance simply exposes
     * the edge-specific helpers (`inEdges` / `outEdges` / `edges`) and
     * defaults its `create()` to {@see \oihana\arango\clients\collection\enums\CollectionType::EDGE}.
     * The underlying transport, retry policy and host-ring failover
     * are shared with this database.
     *
     * @param string $name Name of the target edge collection.
     *
     * @return EdgeCollection
     */
    public function edgeCollection( string $name ) : EdgeCollection
    {
        return new EdgeCollection( $this , $name ) ;
    }

    /**
     * Returns true when this database currently exists on the server.
     *
     * Internally calls {@see ArangoClient::listDatabases()} and checks
     * whether {@see $name} is included in the result.
     *
     * @return bool
     *
     * @throws ArangoException
     */
    public function exists() : bool
    {
        return in_array( $this->name , $this->client->listDatabases() , true ) ;
    }

    /**
     * Asks the server for the execution plan the optimizer would use
     * for the given AQL query, without running it.
     *
     * Wraps `POST /_api/explain`. The returned array follows the wire
     * shape verbatim — `plan` (single-plan response) or `plans`
     * (`{ allPlans: true }`) plus `warnings`, `cacheable`, and
     * `stats`. Decoding stays raw because the plan tree is deep and
     * volatile (optimizer rules evolve between server versions), so
     * a typed value object would be a fragile bet.
     *
     * Typical use cases:
     * - confirm an index is actually used (look for `IndexNode` in the plan),
     * - inspect the join order the optimizer chose,
     * - compare alternative plans with `[ 'allPlans' => true ]`.
     *
     * @param AqlQuery|string       $query    Either a built {@see AqlQuery} or a raw AQL string.
     * @param array<string, mixed>  $bindVars Bind values for the raw string form. MUST be empty when `$query` is an {@see AqlQuery}.
     * @param array<string, mixed>  $options  Server-side explain options (`allPlans`, `maxNumberOfPlans`, `optimizer.rules`, …).
     *
     * @return array<string, mixed> Raw server response.
     *
     * @throws InvalidArgumentException When `$query` is an {@see AqlQuery} and `$bindVars` is not empty.
     * @throws ArangoException          When the server returns an error (e.g. an unparseable query).
     */
    public function explain
    (
        AqlQuery|string $query ,
        array           $bindVars = [] ,
        array           $options  = [] ,
    )
    : array
    {
        if ( $query instanceof AqlQuery )
        {
            if ( count( $bindVars ) > 0 )
            {
                throw new InvalidArgumentException
                (
                    'Database::explain() does not accept bind vars when $query is an AqlQuery (use AqlQuery::$bindVars instead).' ,
                ) ;
            }
            $bindVars = $query->bindVars ;
            $query    = $query->query ;
        }

        $body = [ CursorField::QUERY => $query ] ;

        if ( $bindVars !== [] )
        {
            $body[ CursorField::BIND_VARS ] = (object) $bindVars ;
        }

        if ( $options !== [] )
        {
            $body[ CursorField::OPTIONS ] = $options ;
        }

        $response = $this->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::EXPLAIN ,
            body   : $body ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Returns the database name this instance is bound to.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name ;
    }

    /**
     * Returns a {@see Graph} instance bound to the given name in this
     * database.
     *
     * No HTTP call is made — the handle is purely client-side. Use
     * {@see createGraph()} to actually create the graph on the server,
     * or {@see Graph::create()} on the returned instance.
     *
     * @param string $name Graph name.
     *
     * @return Graph
     */
    public function graph( string $name ) : Graph
    {
        return new Graph( $this , $name ) ;
    }

    /**
     * Returns a list of {@see Graph} handles for every graph
     * currently registered on this database.
     *
     * Hits `GET /_api/gharial`, then wraps each entry's name into a
     * fresh {@see Graph} instance. For the raw descriptions (with
     * edge definitions, orphan collections, etc.), use
     * {@see listGraphs()}.
     *
     * @return array<int, Graph>
     *
     * @throws ArangoException When the request fails.
     */
    public function graphs() : array
    {
        $graphs = [] ;

        foreach ( $this->listGraphs() as $description )
        {
            $name = $description[ Graph::NAME_FIELD ] ?? null ;
            if ( is_string( $name ) && $name !== '' )
            {
                $graphs[] = new Graph( $this , $name ) ;
            }
        }

        return $graphs ;
    }

    /**
     * Lists the raw server-side descriptions of every analyzer
     * currently registered on this database.
     *
     * Wraps `GET /_api/analyzer`. Returns the payload verbatim —
     * each entry carries at least `name` / `type` / `features` /
     * `properties`.
     *
     * Server-side built-in analyzers (`identity`, `text_en`,
     * `text_de`, …) are included in the listing — filter them out
     * on the caller side when iterating over user-defined
     * analyzers only.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException When the request fails.
     */
    public function listAnalyzers() : array
    {
        $response = $this->request
        (
            method : HttpMethod::GET ,
            path   : ArangoRoute::ANALYZER ,
        ) ;

        $body   = is_array( $response->body ) ? $response->body : [] ;
        $result = $body[ AnalyzerField::RESULT ] ?? null ;

        return is_array( $result ) ? $result : [] ;
    }

    /**
     * Lists the raw server-side descriptions of every graph currently
     * registered on this database.
     *
     * Wraps `GET /_api/gharial`. Returns the payload verbatim — each
     * entry carries at least `_key` / `_id` / `name` /
     * `edgeDefinitions` / `orphanCollections`.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException When the request fails.
     */
    public function listGraphs() : array
    {
        $response = $this->request
        (
            method : HttpMethod::GET ,
            path   : ArangoRoute::GHARIAL ,
        ) ;

        $body  = is_array( $response->body ) ? $response->body : [] ;
        $graphs = $body[ 'graphs' ] ?? null ;

        return is_array( $graphs ) ? $graphs : [] ;
    }

    /**
     * Lists the raw server-side descriptions of every view
     * currently registered on this database.
     *
     * Wraps `GET /_api/view`. Returns the payload verbatim — each
     * entry carries at least `name` / `type` / `id` /
     * `globallyUniqueId`.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException When the request fails.
     */
    public function listViews() : array
    {
        $response = $this->request
        (
            method : HttpMethod::GET ,
            path   : ArangoRoute::VIEW ,
        ) ;

        $body   = is_array( $response->body ) ? $response->body : [] ;
        $result = $body[ ViewField::RESULT ] ?? null ;

        return is_array( $result ) ? $result : [] ;
    }

    /**
     * Lists the streaming transactions currently active on this
     * database.
     *
     * Wraps `GET /_api/transaction`. Returns the server-side payload
     * verbatim — each entry exposes at least `id` and `state` (one
     * of {@see \oihana\arango\clients\transaction\enums\TransactionStatus},
     * keyed `state` here rather than `status` on this list endpoint).
     *
     * Useful for admin tooling and for diagnosing orphan transactions
     * that survived a client crash.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException When the request fails.
     */
    public function listTransactions() : array
    {
        $response = $this->request
        (
            method : HttpMethod::GET ,
            path   : ArangoRoute::TRANSACTION ,
        ) ;

        $body         = is_array( $response->body ) ? $response->body : [] ;
        $transactions = $body[ 'transactions' ] ?? null ;

        return is_array( $transactions ) ? $transactions : [] ;
    }

    /**
     * Parses an AQL query and returns its AST + the list of
     * collections it references, without executing it.
     *
     * Wraps `POST /_api/query`. The naming mirrors arangojs
     * (`db.parse()`). Useful as a lightweight validation step: a
     * malformed query is rejected here with the same `errorNum` the
     * server would emit at execution time, but without going through
     * the cursor / batch machinery.
     *
     * Bind values are intentionally ignored by the endpoint — only
     * the query string itself is parsed. Pass an `AqlQuery` if it is
     * what you already hold; only its `->query` part is sent over
     * the wire.
     *
     * @param AqlQuery|string $query Either a built {@see AqlQuery} or a raw AQL string. Only the query string is forwarded.
     *
     * @return array<string, mixed> Raw server response (`parsed`, `collections`, `bindVars`, `ast`, …).
     *
     * @throws ArangoException When the server returns an error (the typical signal for an unparseable query).
     */
    public function parse( AqlQuery|string $query ) : array
    {
        $body =
        [
            CursorField::QUERY => $query instanceof AqlQuery ? $query->query : $query ,
        ] ;

        $response = $this->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::QUERY ,
            body   : $body ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Executes an AQL query against this database and returns a {@see Cursor}
     * for iterating over the results.
     *
     * The first argument can be either:
     * - a fully-built {@see AqlQuery} (typically produced by {@see \oihana\arango\clients\aql\helpers\aql()} or by a query builder),
     * - a raw query string, in which case `$bindVars` carries the bind values.
     *
     * `$options` is a free-form array forwarded as-is to the server's
     * `POST /_api/cursor` endpoint. Common keys are `count`, `batchSize`,
     * `fullCount`, `ttl`, `memoryLimit`, `cache`, `options.profile`, …
     * (see https://docs.arangodb.com/stable/aql/how-to-invoke-aql/#cursor-api).
     *
     * Example:
     * ```php
     * use function oihana\arango\clients\aql\helpers\aql ;
     *
     * $cursor = $db->query
     * (
     *     aql( 'FOR u IN users FILTER u.active == ? RETURN u' , true ) ,
     *     options : [ 'count' => true ] ,
     * ) ;
     *
     * foreach ( $cursor as $user ) { ... }
     * echo count( $cursor ) ;
     * ```
     *
     * @param AqlQuery|string       $query    Either a built {@see AqlQuery} or a raw AQL string.
     * @param array<string, mixed>  $bindVars Bind values for the raw string form. MUST be empty when `$query` is an {@see AqlQuery}.
     * @param array<string, mixed>  $options  Server-side cursor options (count, batchSize, fullCount, …).
     *
     * @return Cursor
     *
     * @throws InvalidArgumentException When `$query` is an {@see AqlQuery} and `$bindVars` is not empty.
     * @throws ArangoException          When the server returns an error.
     */
    public function query
    (
        AqlQuery|string $query ,
        array           $bindVars = [] ,
        array           $options  = [] ,
    )
    : Cursor
    {
        if ( $query instanceof AqlQuery )
        {
            if ( count( $bindVars ) > 0 )
            {
                throw new InvalidArgumentException
                (
                    'When passing an AqlQuery instance, $bindVars must remain empty; bind values are carried by the AqlQuery itself.'
                ) ;
            }
            $aql = $query ;
        }
        else
        {
            $aql = new AqlQuery( $query , $bindVars ) ;
        }

        $body = array_merge
        (
            [
                CursorField::QUERY     => $aql->query ,
                CursorField::BIND_VARS => (object) $aql->bindVars ,
            ] ,
            $options ,
        ) ;

        $response = $this->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::CURSOR ,
            body   : $body ,
        ) ;

        return new Cursor( $this , is_array( $response->body ) ? $response->body : [] ) ;
    }

    /**
     * Sends a request scoped to this database. The URL is automatically
     * prefixed with `/_db/{name}`.
     *
     * @param string                           $method        HTTP verb.
     * @param string                           $path          API path beginning with `/`.
     * @param array<string, mixed>|string|null $body          Request body. When an array is passed it is JSON-encoded; when a string is passed it is sent verbatim as the raw HTTP body (used for `/_api/import` JSON Lines payloads — caller must then supply the matching `Content-Type` header).
     * @param array<string, mixed>             $query         Query string parameters.
     * @param array<string, string>            $headers       Extra headers (merged with the per-request defaults).
     * @param string|null                      $transactionId Optional streaming transaction id; when non-null, the transport stamps `x-arango-trx-id: {id}` on the outbound request so the server attaches the operation to the running transaction.
     *
     * @return HttpResponse
     *
     * @throws ArangoException
     */
    public function request
    (
        string            $method ,
        string            $path ,
        array|string|null $body          = null ,
        array             $query         = [] ,
        array             $headers       = [] ,
        ?string           $transactionId = null ,
    )
    : HttpResponse
    {
        return $this->client->transport->request
        (
            method           : $method ,
            path             : $path ,
            body             : $body ,
            query            : $query ,
            headers          : $headers ,
            databaseOverride : $this->name ,
            transactionId    : $transactionId ,
        ) ;
    }

    /**
     * Wraps an existing server-side transaction id into a fresh
     * {@see Transaction} handle bound to this database.
     *
     * No HTTP call is made — the handle is purely client-side. Use
     * this when a transaction id has been obtained out-of-band (for
     * example through {@see listTransactions()} or shared by another
     * process) and the caller wants to commit, abort or inspect it.
     *
     * @param string $id Server-side transaction id.
     *
     * @return Transaction
     */
    public function transaction( string $id ) : Transaction
    {
        return new Transaction( $this , $id ) ;
    }

    /**
     * Returns a {@see View} instance bound to the given name in
     * this database.
     *
     * No HTTP call is made — the handle is purely client-side.
     * Use {@see createView()} to actually create the view on the
     * server, or {@see View::create()} on the returned instance.
     *
     * @param string $name View name.
     *
     * @return View
     */
    public function view( string $name ) : View
    {
        return new View( $this , $name ) ;
    }

    /**
     * Returns a list of {@see View} handles for every view
     * currently registered on this database.
     *
     * Hits `GET /_api/view`, then wraps each entry's name into a
     * fresh {@see View} instance. For the raw descriptions (with
     * `type`, `id`, `globallyUniqueId`), use {@see listViews()}.
     *
     * @return array<int, View>
     *
     * @throws ArangoException When the request fails.
     */
    public function views() : array
    {
        $views = [] ;

        foreach ( $this->listViews() as $description )
        {
            $name = $description[ ViewField::NAME ] ?? null ;
            if ( is_string( $name ) && $name !== '' )
            {
                $views[] = new View( $this , $name ) ;
            }
        }

        return $views ;
    }

    /**
     * High-level streaming-transaction helper: starts a transaction
     * with the given collections scope, runs `$callback` inside
     * {@see Transaction::step()}, and commits on success or aborts
     * on failure.
     *
     * The callback receives the {@see Transaction} handle as its
     * single argument, so it can inspect `id` / `status()` if needed.
     * Every plain CRUD call inside the callback automatically carries
     * the `x-arango-trx-id` header (because the callback runs inside
     * `step()`), so the caller almost never needs to use the handle
     * directly — it's there for the edge cases.
     *
     * Lifecycle guarantees:
     * - On a clean return from `$callback`, the transaction is
     *   `commit()`-ed and the return value is propagated up.
     * - On a `\Throwable` raised from `$callback`, `abort()` is
     *   called best-effort (any exception from `abort()` is
     *   silently swallowed — the server may have already terminated
     *   the transaction) and the original exception is re-thrown.
     * - Do NOT call `commit()` or `abort()` from inside `$callback` —
     *   the helper owns the lifecycle. Doing so will surface as a
     *   `1657` / `1658` error when the helper tries to terminate
     *   the transaction itself.
     *
     * Example:
     * ```php
     * $newKey = $db->withTransaction
     * (
     *     callback : static function ( Transaction $trx ) use ( $db , $payload )
     *     {
     *         $user  = $db->collection( 'users' )->insert( $payload , [ 'returnNew' => true ] ) ;
     *         $db->collection( 'audits' )->insert
     *         (
     *             [ 'event' => 'user.created' , 'userKey' => $user->getKey() ] ,
     *         ) ;
     *         return $user->getKey() ;
     *     } ,
     *     write : [ 'users' , 'audits' ] ,
     * ) ;
     * ```
     *
     * @param callable(Transaction): mixed $callback  User-provided block.
     * @param array<int, string>           $write     Collections written by the transaction.
     * @param array<int, string>           $read      Collections read by the transaction.
     * @param array<int, string>           $exclusive Collections held exclusively for the transaction.
     * @param array<string, mixed>         $options   Server-side options (see {@see beginTransaction()}).
     *
     * @return mixed The value returned by `$callback`.
     *
     * @throws Throwable Whatever the callback throws (after the transaction has been aborted).
     */
    public function withTransaction
    (
        callable $callback ,
        array    $write     = [] ,
        array    $read      = [] ,
        array    $exclusive = [] ,
        array    $options   = [] ,
    )
    : mixed
    {
        $trx = $this->beginTransaction
        (
            write     : $write ,
            read      : $read ,
            exclusive : $exclusive ,
            options   : $options ,
        ) ;

        try
        {
            $result = $trx->step( static fn() : mixed => $callback( $trx ) ) ;
            $trx->commit() ;
            return $result ;
        }
        catch ( Throwable $e )
        {
            try
            {
                $trx->abort() ;
            }
            catch ( ArangoException )
            {
                // Best-effort: the server may have already terminated
                // the transaction. Swallow so the original exception
                // propagates verbatim.
            }
            throw $e ;
        }
    }

    /**
     * Extracts the transaction id from a `/_api/transaction/*`
     * response body, unwrapping the outer `result` envelope when
     * present.
     *
     * @param mixed $body Decoded response body.
     *
     * @return string The transaction id, or an empty string when the field is absent.
     */
    private function extractTransactionId( mixed $body ) : string
    {
        if ( !is_array( $body ) )
        {
            return '' ;
        }

        $payload = unwrapField( $body , self::TRANSACTION_RESULT_FIELD , $body ) ;
        $id      = $payload[ self::TRANSACTION_ID_FIELD ] ?? null ;

        return is_string( $id ) ? $id : '' ;
    }
}
