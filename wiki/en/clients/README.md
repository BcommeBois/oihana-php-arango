# The ArangoDB HTTP client

`oihana/php-arango` exposes two layers to talk to ArangoDB:

| Layer | Folder | What |
|---|---|---|
| **Low-level HTTP client** | [`src/oihana/arango/clients/`](../../../src/oihana/arango/clients/) | Guzzle transport, authentication, retry, cluster failover, raw requests against the arangod REST API. |
| **High-level façade** | [`src/oihana/arango/db/`](../../../src/oihana/arango/db/) ([`ArangoDB`](../getting-started/quickstart.md)) | Hydration, exception wrapping, `prepare/execute`, AQL helpers — built on top of the client. |

This page covers the **client** layer. For the façade quickstart, see [Quickstart `ArangoDB`](../getting-started/quickstart.md). For business models (`Documents`, `Edges`), see [Models](../models.md).

> The client is designed as a **standalone** library — it does not depend on the `db/` layer, on Slim, or on Symfony Console. You can use it as-is for a CLI script, a worker, or an integration test suite. Its design is inspired by the official JavaScript library [`arangojs`](https://github.com/arangodb/arangojs).

## Learn the client progressively

If you've never used ArangoDB, read these pages in order. Each one builds on the previous.

| # | Page | Audience |
|---|---|---|
| 1 | [Getting started](getting-started.md) | **Beginners** — your first `ArangoClient`, your first document, in seven small steps. |
| 2 | [Collections and documents](documents.md) | Beginner → intermediate — full CRUD, batch operations, bulk JSON-Lines import, edges. |
| 3 | [AQL queries and Cursors](aql.md) | Intermediate — `aql()` helper, `AqlQuery`, bind variables, lazy `Cursor` with `map` / `forEach` / `reduce` / `flatMap`. |
| 4 | [Graphs](graphs.md) | Intermediate — named *gharial* graphs, `EdgeDefinition`, vertex/edge collections with type-safe inserts, AQL traversal. |
| 5 | [Transactions](transactions.md) | Advanced — streaming transactions, `withTransaction()` auto-commit/abort, scoping collection access. |
| 6 | [Indexes](indexes.md) | Intermediate — seven typed index classes (`PersistentIndex`, `GeoIndex`, `TtlIndex`, `MDIIndex`, `VectorIndex`, `InvertedIndex`, `FulltextIndex`). |
| 7 | [ArangoSearch](arangosearch.md) | Advanced — analyzers and views for multi-collection full-text search with `SEARCH` / `BM25` / `PHRASE`. |
| 8 | [Resilience and authentication](resilience.md) | Ops — auth modes and 401 auto-refresh, retry policy, multi-host failover, timeouts, dirty reads. |

The rest of this page is a **reference** — architecture diagram, quick example, method tables for `ArangoClient` and `Database`, when to use the client vs the high-level façade.

## Architecture

```
ArangoClient ─────► HttpTransport (Guzzle) ───► arangod
     │                    │
     │                    ├─► RetryPolicy   (1200 conflict, 3002 maintenance)
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

## Quick example

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

## Configuration and resilience

Authentication modes (Basic, JWT with 401 auto-refresh), retry policy on transient errors, multi-host failover, timeouts, keep-alive, and dirty reads are all covered in a dedicated page — see [Resilience and authentication](resilience.md).

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
