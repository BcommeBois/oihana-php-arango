# The ArangoDB HTTP client

`oihana/php-arango` exposes two layers to talk to ArangoDB:

| Layer | Folder | What |
|---|---|---|
| **Low-level HTTP client** | [`src/oihana/arango/clients/`](../../../src/oihana/arango/clients/) | Guzzle transport, authentication, retry, cluster failover, raw requests against the arangod REST API. |
| **High-level façade** | [`src/oihana/arango/db/`](../../../src/oihana/arango/db/) ([`ArangoDB`](../getting-started/quickstart.md)) | Hydration, exception wrapping, `prepare/execute`, AQL helpers — built on top of the client. |

This page covers the **client** layer. For the façade quickstart, see [Quickstart `ArangoDB`](../getting-started/quickstart.md). For business models (`Documents`, `Edges`), see [Models](../models.md).

> The client is designed as a **standalone** library — it does not depend on the `db/` layer, on Slim, or on Symfony Console. You can use it as-is for a CLI script, a worker, or an integration test suite. Its design is inspired by the official JavaScript library [`arangojs`](https://github.com/arangodb/arangojs).

## Architecture

```
ArangoClient ─────► HttpTransport (Guzzle) ───► arangod
     │                    │
     │                    ├─► RetryPolicy   (1209 conflict, 3002 maintenance)
     │                    └─► HostRing      (round-robin cluster failover)
     │
     └──► Database (one hub per database)
              ├─► Collection / EdgeCollection (CRUD + indexes + batch)
              ├─► Cursor             (Iterator + map/forEach/reduce/flatMap)
              ├─► Transaction        (streaming, withTransaction auto-commit/abort)
              ├─► Graph / GraphVertex/EdgeCollection
              ├─► Analyzer           (identity, text, norm, stem)
              └─► View               (arangosearch — SEARCH, PHRASE, BM25)
```

## Getting started

```php
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\clients\enums\AuthType ;

$client = new ArangoClient( new ClientOptions(
    endpoints : [ 'tcp://127.0.0.1:8529' ] ,
    database  : 'my_database' ,
    authType  : AuthType::BASIC ,
    user      : 'root' ,
    password  : 'secret' ,
) ) ;

$db = $client->database() ;                          // Database factory
$users = $db->collection( 'users' ) ;                // Collection factory
$doc = $users->document( 'abc' ) ;                   // GET /_api/document/users/abc
$count = $users->count() ;                           // GET /_api/collection/users/count

// AQL
use oihana\arango\clients\aql\AqlQuery ;
use function oihana\arango\clients\aql\helpers\aql ;

$cursor = $db->query( aql(
    'FOR u IN @@coll FILTER u.active == @active RETURN u' ,
    bindVars: [ '@coll' => 'users' , 'active' => true ] ,
) ) ;

foreach ( $cursor as $user )
{
    echo $user[ 'name' ] . PHP_EOL ;
}
```

## `ArangoClient` — entry point

An `ArangoClient` instance represents a connection **to a cluster** (one or more endpoints). Its configuration is immutable once passed — it is carried by a `readonly` `ClientOptions`.

| Method | Description |
|---|---|
| `database( ?string $name = null ) : Database` | `Database` factory for the given name (or the one passed in `ClientOptions::$database`). |
| `createDatabase( string $name ) : void` | `POST /_api/database`. |
| `dropDatabase( string $name ) : void` | `DELETE /_api/database/{name}`. |
| `listDatabases() : array` | `GET /_api/database`. |
| `version() : array` | `GET /_api/version`. |
| `time() : float` | `GET /_admin/time` — server wall-clock in float seconds. |
| `availability( bool $graceful = true ) : string\|false` | `GET /_admin/server/availability` — returns the server mode (`default` / `readonly`) or `false`. |
| `login( string $user , string $password ) : string` | `POST /_open/auth` — fetches a JWT. Automatically switches the transport to Bearer. |
| `useBearerAuth( ?string $token ) : void` | Forces a Bearer token (or reverts to Basic with `null`). |
| `useBasicAuth( string $user , string $password ) : void` | Forces Basic credentials. |
| `request( string $method , string $path , …)` | Raw request (use this for endpoints not yet wrapped). |

## `Database` — hub

Every operation tied to a *database* goes through a `Database`. An instance is obtained via `$client->database( 'name' )`.

| Method | What |
|---|---|
| `collection( string $name ) : Collection` | Document collection factory. |
| `edgeCollection( string $name ) : EdgeCollection` | Edge collection factory. |
| `collections( bool $includeSystem = false ) : array` | Typed list. |
| `query( AqlQuery\|string $query ) : Cursor` | Executes an AQL query, returns a lazy `Cursor`. |
| `explain( ... )` / `parse( ... )` | AQL diagnostics (execution plan + parsing). |
| `beginTransaction( ... ) : Transaction` / `transaction( string $id ) : Transaction` / `withTransaction( callable $fn , ... )` | *Streaming* transactions (multi-document). `withTransaction` handles commit/abort automatically with `try / finally`. |
| `listTransactions() : array` | Active transactions on this database. |
| `graph( string $name )` / `graphs()` / `listGraphs()` / `createGraph( ... )` | *Gharial* graph management. |
| `analyzer( string $name )` / `analyzers()` / `listAnalyzers()` / `createAnalyzer( ... )` | ArangoSearch analyzers. |
| `view( string $name )` / `views()` / `listViews()` / `createView( ... )` | ArangoSearch views. |
| `exists()` / `create()` / `drop()` | Database lifecycle. |

## Authentication

Three modes supported through `ClientOptions::$authType`:

- `AuthType::BASIC` — credentials sent as `Authorization: Basic …` on every request. Default.
- `AuthType::JWT` (alias `BEARER`) — token sent as `Authorization: Bearer …`. The token can be obtained via `$client->login( $user , $password )` which switches the transport automatically.
- **401 auto-refresh.** If a Bearer request receives a 401, the transport tries a single `login` then replays the request. The flag is carried by `HttpTransport` (not by `ClientOptions`, which stays `readonly`).

```php
// Start in Basic, then exchange for a JWT
$token = $client->login( 'root' , 'secret' ) ;
// The client is now in Bearer automatically.

// Switch back to Basic explicitly
$client->useBasicAuth( 'root' , 'secret' ) ;
```

## Resilience: retry and cluster failover

`ClientOptions::$endpoints` accepts **multiple URLs** — the [`HostRing`](https://github.com/BcommeBois/oihana-php-arango/blob/main/src/oihana/arango/clients/http/HostRing.php) class picks a host in round-robin and falls over to the next one on network failure.

`RetryPolicy` kicks in for **Arango error codes** that are *safe-to-retry*:

- `1209` — `ERROR_ARANGO_CONFLICT` (write-write conflict, the engine can be retried).
- `3002` — `ERROR_CLUSTER_AGENCY_*` / maintenance — typically transient during a *leader switch*.

```php
$client = new ArangoClient( new ClientOptions(
    endpoints : [ 'tcp://node-1:8529' , 'tcp://node-2:8529' , 'tcp://node-3:8529' ] ,
    database  : 'app' ,
    user      : 'root' ,
    password  : 'secret' ,
    reconnect : true ,        // keep keep-alive on reconnect
) ) ;
```

## Dirty reads (replicas)

In a cluster setup, you can allow reads from replicas by turning on the global `allowDirtyRead` flag — it injects the `x-arango-allow-dirty-read: true` header on **every** request:

```php
$client = new ArangoClient( new ClientOptions(
    endpoints       : [ ... ] ,
    database        : 'app' ,
    user            : 'root' ,
    password        : 'secret' ,
    allowDirtyRead  : true ,    // OPT-IN
) ) ;
```

The server is then free to serve the request from a follower; use this only for reads that tolerate slight replication lag.

## When to use the client directly vs the façade

| Need | Pick |
|---|---|
| Standalone CLI script, integration test, worker | Direct client (`ArangoClient` + `Database`). |
| Application with PSR-11 DI, reusable `Documents` models, before/after signals, legacy `oihana\arango\client\Exception` wrapping | Façade `ArangoDB` ([Quickstart](../getting-started/quickstart.md)). |
| A single ad-hoc AQL query in an app already consuming the façade | Pull the client via `$arangoDB->getClient()` (discouraged unless you have a reason — prefer `prepare/execute` on the façade). |

## See also

- [Quickstart `ArangoDB`](../getting-started/quickstart.md) — the high-level façade.
- [Models `Documents` and `Edges`](../models.md) — the business layer.
- [Indexes](../indexes.md) — typed index catalog.
- [Testing](../testing.md) — the two live commands `arango:test:clients` and `arango:test:facade`.
- [arangojs (official JS lib)](https://github.com/arangodb/arangojs) — architectural reference.
