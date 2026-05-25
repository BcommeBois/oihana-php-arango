# Resilience and authentication

This page covers everything the client does to keep running when the network — or the cluster — gets noisy: authentication modes and the 401 auto-refresh, retry on transient errors, multi-host failover, timeouts, keep-alive, and dirty reads.

If you've just landed: the defaults are sensible for local development. Read this page when you move to production.

## Authentication

`ClientOptions::$authType` accepts two values from the `AuthType` enum:

| Mode | What gets sent |
|---|---|
| `AuthType::BASIC` (default) | `Authorization: Basic base64(user:password)` on every request. |
| `AuthType::JWT` (alias `BEARER`) | `Authorization: Bearer <jwt>` on every request. |

Setup is straightforward:

```php
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\clients\enums\AuthType ;

$client = new ArangoClient( new ClientOptions(
    endpoints : [ 'http://127.0.0.1:8529' ] ,
    database  : 'app' ,
    authType  : AuthType::JWT ,
    user      : 'root' ,
    password  : 'secret' ,
) ) ;

$client->login( 'root' , 'secret' ) ;   // obtains a JWT and stores it for re-use
```

### 401 auto-refresh

When the transport sees a `401` on any request, and **basic credentials are still stored**, it transparently:

1. Calls `login(user, password)` against `/_open/auth` to fetch a fresh JWT.
2. Updates the in-memory token.
3. **Replays the original request once** with the new token.

This doesn't count against the retry budget. The refresh fires at most once per request — re-entry is blocked by an internal flag, so a broken auth setup doesn't loop forever.

If the refresh itself fails, the original 401 is rethrown so the caller sees the root cause.

### Switching auth at runtime

```php
$client->useBasicAuth( 'admin' , 'newsecret' ) ;   // switch to Basic; clears any JWT
$client->useBearerAuth( $jwt ) ;                    // switch to JWT explicitly
$client->useBearerAuth( null ) ;                    // back to Basic mode
$client->login( 'admin' , 'newsecret' ) ;           // Basic → JWT in one call
```

Basic credentials remain stored even when you switch to Bearer — that's what keeps the 401 auto-refresh working.

## Retry policy

Transient errors are retried automatically. The transport's `RetryPolicy` decides what counts as "transient":

| Source | Detail |
|---|---|
| ArangoDB `errorNum` **1200** (`ARANGO_CONFLICT`) | Write-write conflict on the same revision. HTTP 409. |
| ArangoDB `errorNum` **3002** (`CLUSTER_BACKEND_UNAVAILABLE`) | DBServer temporarily down during cluster maintenance. HTTP 503. |
| Network errors | `ConnectException`, transport-level `GuzzleException` (DNS, refused TCP, timeouts). |

**Retry budget** — defaults:

- 3 total attempts (initial + 2 retries).
- Exponential backoff: `100 ms × 2^(n-1)`, capped at `5 000 ms`. So delays land at 100 ms, 200 ms, 400 ms, …
- The 401 auto-refresh is **separate** from this budget.

When all attempts are exhausted, the last exception bubbles up.

## Multi-host failover

`ClientOptions::$endpoints` accepts a list. The `HostRing` rotates through them.

```php
$client = new ArangoClient( new ClientOptions(
    endpoints : [
        'http://node-1:8529' ,
        'http://node-2:8529' ,
        'http://node-3:8529' ,
    ] ,
    database  : 'app' ,
    user      : 'root' ,
    password  : 'secret' ,
) ) ;
```

When a request fails with a retryable transport error, the transport calls `HostRing::next()` to advance the cursor before retrying. The ring stays on the failing host if a retry succeeds — the cursor only moves when something actually breaks. State persists for the lifetime of the `ArangoClient` instance.

> Legacy URL schemes are normalized: `tcp://` → `http://`, `ssl://`/`tls://` → `https://`. Mixing protocols in one ring works, but it's clearer to keep them uniform.

## Dirty reads (replicas)

In a cluster, you can let reads be served by followers — at the cost of reading slightly stale data.

```php
$client = new ArangoClient( new ClientOptions(
    endpoints      : [ ... ] ,
    database       : 'app' ,
    user           : 'root' ,
    password       : 'secret' ,
    allowDirtyRead : true ,    // OPT-IN
) ) ;
```

When enabled, the header `x-arango-allow-dirty-read: true` is stamped on **every** outbound request, not just GETs. Single-server deployments ignore it silently. Use it only for reads that can tolerate replication lag.

## Timeouts

Four knobs, with different scopes:

| Option | Maps to | Default | Scope |
|---|---|---|---|
| `connectTimeout` | Guzzle `connect_timeout` | 5 s | TCP/TLS handshake. |
| `requestTimeout` | Guzzle `timeout` | 30 s | Whole request (connect + read). |
| `timeout` | Falls back into `requestTimeout` if the latter is null. | 30 s | Single legacy knob — keep both at the same value if you set them. |
| `maxRuntime` | Query string param to the server | `null` (unlimited) | Server-side per-query execution budget, in seconds. |

`requestTimeout` is what you usually adjust. `maxRuntime` is enforced by the server and useful for AQL queries that might scan a lot.

## Connection mode and keep-alive

```php
use oihana\arango\clients\enums\ConnectionMode ;

new ClientOptions(
    // ...
    connection : ConnectionMode::KEEP_ALIVE ,   // default — reuse TCP
    reconnect  : true ,                          // (default) prepare for stale-socket reconnect
) ;
```

`ConnectionMode::CLOSE` opens and closes a fresh TCP per request — useful only for very short-lived scripts where you don't want any pooling. For everything else, leave it on `KEEP_ALIVE`.

`reconnect: true` lets Guzzle silently re-establish a connection when the server has dropped a keep-alive socket on its side. Disabling it surfaces the broken socket as an exception — rarely what you want.

## Recipe — production 3-node cluster

```php
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\clients\enums\AuthType ;
use oihana\arango\clients\enums\ConnectionMode ;

$client = new ArangoClient( new ClientOptions(
    endpoints      : [
        'https://coord-1.example.com:8529' ,
        'https://coord-2.example.com:8529' ,
        'https://coord-3.example.com:8529' ,
    ] ,
    database       : 'app' ,
    authType       : AuthType::JWT ,
    user           : 'service' ,         // kept for 401 auto-refresh
    password       : $secret ,
    connectTimeout : 5 ,
    requestTimeout : 30 ,
    connection     : ConnectionMode::KEEP_ALIVE ,
    reconnect      : true ,
    allowDirtyRead : false ,             // strong consistency for the whole app
) ) ;

$client->login( 'service' , $secret ) ;   // mint the first JWT
```

From here on:
- Requests transparently round-robin across the three coordinators on transient failure.
- A 401 (expired JWT) triggers a silent re-login and replay.
- ArangoDB conflicts (1200) and maintenance hiccups (3002) are retried up to twice with exponential backoff.

## Where next

- [HTTP client overview](README.md) — architecture diagram and quick example.
- [Getting started](getting-started.md) — the seven-step intro for first-time readers.
- [Transactions](transactions.md) — `ConflictException::isSafeToRetry()` is especially relevant inside transactions.
