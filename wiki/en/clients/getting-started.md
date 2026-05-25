# Getting started with the HTTP client

This page walks you through writing your first script against ArangoDB using the **low-level client** — `ArangoClient`. By the end you'll have a small program that connects to a server, creates a database, inserts a document, reads it back, updates it, and cleans up.

It assumes you have **never** used ArangoDB before, and it does not require any of the rest of this documentation.

## Prerequisites

- ArangoDB 3.11+ running locally on `tcp://127.0.0.1:8529`. The fastest way: `docker run -p 8529:8529 -e ARANGO_ROOT_PASSWORD=secret arangodb/arangodb:latest`.
- PHP 8.4+ available on your machine.
- A project where `composer require oihana/php-arango` has been run.

> Don't have ArangoDB handy? You can also skip ahead to the [smoke tests](../testing.md) — the bundled `arango:test:clients` command spins up an ephemeral database and exercises every public method.

## A 30-second mental model

ArangoDB stores **documents** — JSON objects — inside **collections** — buckets of documents. Each document has three reserved fields managed by the server:

| Field | Set by | What it is |
|---|---|---|
| `_key` | client (or server if omitted) | A unique identifier within the collection, e.g. `"123"`. |
| `_id` | server | The full handle: `"users/123"`. |
| `_rev` | server | A revision string that changes on every write. Used for optimistic concurrency. |

You only ever set `_key` (and you can let the server pick one). The other two are derived.

## Step 1 — Connect

Create an `ArangoClient`. The only mandatory inputs are the endpoint, the database name, and credentials.

```php
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;

$client = new ArangoClient( new ClientOptions(
    endpoints : [ 'tcp://127.0.0.1:8529' ] ,
    database  : 'tutorial' ,
    user      : 'root' ,
    password  : 'secret' ,
) ) ;
```

No HTTP request happens yet — `ArangoClient` is a configured handle, not an active connection.

## Step 2 — Make sure the database exists

The `tutorial` database doesn't exist yet. Ask for a handle, then create it if needed.

```php
$db = $client->database( 'tutorial' ) ;   // factory, no HTTP

if ( ! $db->exists() )
{
    $db->create() ;
}
```

`exists()` issues one lightweight request. `create()` issues a `POST /_api/database`.

## Step 3 — Make sure the collection exists

Same pattern.

```php
$users = $db->collection( 'users' ) ;     // factory, no HTTP

if ( ! $users->exists() )
{
    $users->create() ;
}
```

If you'd rather have collections appear on demand, set `create: true` in `ClientOptions` (it's the default) — the client will auto-create missing collections at first insert.

## Step 4 — Insert your first document

```php
$doc = $users->insert(
    [ 'name' => 'Marc' , 'role' => 'admin' ] ,
    [ 'returnNew' => true ] ,
) ;

echo $doc->getKey() ;        // e.g. '12345' — server-assigned
echo $doc->getId()  ;        // 'users/12345'
echo $doc->get( 'name' ) ;   // 'Marc' (thanks to returnNew)
```

`insert()` returns a `Document` — an immutable wrapper around the server's response. By default the server returns just `{ _key, _id, _rev }`. With `returnNew: true`, the full inserted document is included and accessible via `$doc->get( ... )`.

## Step 5 — Read it back

```php
$fetched = $users->document( $doc->getKey() ) ;

echo $fetched->get( 'name' ) ;   // 'Marc'
echo $fetched->getRev() ;        // some revision token
```

## Step 6 — Update or replace

Update — PATCH semantics, merges the fields you pass into the existing document:

```php
$updated = $users->update(
    $doc->getKey() ,
    [ 'role' => 'superadmin' ] ,
    [ 'returnNew' => true ] ,
) ;

echo $updated->get( 'name' ) ;   // 'Marc'         (kept)
echo $updated->get( 'role' ) ;   // 'superadmin'   (updated)
```

Replace — PUT semantics, wipes everything except `_key`/`_id`/`_rev` and writes what you pass:

```php
$replaced = $users->replace(
    $doc->getKey() ,
    [ 'name' => 'Marc Alcaraz' ] ,
) ;
// 'role' is gone now.
```

## Step 7 — Clean up

```php
$users->remove( $doc->getKey() ) ;
// $users->truncate() ;    // would empty the whole collection
// $users->drop() ;        // would delete the collection server-side
// $db->drop() ;           // would delete the whole database
```

## What you just learned

- An `ArangoClient` is configuration, not a connection — building it doesn't hit the network.
- `Database` and `Collection` instances are also lazy factories. The first HTTP call usually happens on `exists()`/`create()`/`insert()`.
- Documents are returned as immutable `Document` objects with `getKey()`, `getId()`, `getRev()`, and a generic `get( $field , $default = null )`.
- Reserved fields (`_key`, `_id`, `_rev`) are server-managed unless you explicitly set `_key`.

## When things go wrong

The whole library throws subclasses of `ArangoException`:

| Exception | When | Safe to retry? |
|---|---|---|
| `HttpException` | Generic 4xx/5xx — including `404` (document missing) | No |
| `ConflictException` | `409` — write-write conflict on the same revision | **Yes** |
| `MaintenanceException` | Cluster leader switching, agency maintenance | Yes |
| `NetworkException` | Transport-level failure (DNS, timeout, socket) | Maybe — depends on the operation |

Catch the base if you don't care, or narrow as needed:

```php
use oihana\arango\clients\exceptions\HttpException ;

try
{
    $users->document( 'no-such-key' ) ;
}
catch ( HttpException $e )
{
    if ( $e->getCode() === 404 )
    {
        // Not found.
    }
}
```

## Where next

- [Collections and documents](documents.md) — the full CRUD surface, batch operations, bulk import.
- [HTTP client overview](README.md) — architecture, authentication, retry, cluster failover.
- [Tips and pitfalls](../tips.md) — golden rules to follow in production.
