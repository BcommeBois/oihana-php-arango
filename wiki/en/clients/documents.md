# Collections and documents

This page covers the full surface for working with **one collection** through the HTTP client — every CRUD method on `Collection`, plus batch and bulk operations.

It assumes you've read [Getting started](getting-started.md) and already have a working `ArangoClient`.

## Two lazy factories

`Collection` and `EdgeCollection` are both **lazy handles** — building one does not make any HTTP call.

```php
$users   = $db->collection( 'users' ) ;          // document collection
$friends = $db->edgeCollection( 'friends' ) ;    // edge collection
```

Edges are documents that always carry `_from` and `_to` references between vertex documents. They share every CRUD method below; only `create()` differs in its default `type` (`EDGE` vs `DOCUMENT`).

## Creating a collection

```php
$users->create() ;                                  // type defaults to document
$users->create([ 'waitForSync' => true ]) ;         // forward server options
```

Common options forwarded directly to ArangoDB:

| Option | Effect |
|---|---|
| `waitForSync` | Force fsync on every write (slower, durable). |
| `keyOptions` | Configure server-side key generation strategy. |
| `numberOfShards`, `replicationFactor` | Cluster topology. |
| `schema` | JSON Schema validator. |

See the official [Create a collection](https://docs.arangodb.com/stable/develop/http-api/collections/#create-a-collection) reference for the full list.

## The `Document` object

Every read or write that returns a single document yields a `Document` — an immutable wrapper.

| Method | Returns |
|---|---|
| `getKey()` | `_key` (or `null` if the document has never been persisted) |
| `getId()` | `_id` — fully qualified handle (`collection/key`) |
| `getRev()` | `_rev` — server-managed revision token |
| `get( $field , $default = null )` | Arbitrary field value |
| `has( $field )` | `true` even when the value is `null` |
| `isNew()` | `true` if `_key` has not been assigned |
| `toArray()` | Raw associative array |

## Read

```php
$doc    = $users->document( 'abc' ) ;          // throws HttpException(404) if missing
$exists = $users->documentExists( 'abc' ) ;    // bool, never throws on 404
$count  = $users->count() ;                    // int
```

## Insert

```php
$doc = $users->insert(
    [ 'name' => 'Alice' , 'email' => 'alice@example.com' ] ,
    [ 'returnNew' => true , 'waitForSync' => true ] ,
) ;
```

Common write options (forwarded to ArangoDB):

| Option | What it does |
|---|---|
| `returnNew` | Include the full inserted document in the response. |
| `returnOld` | (update / replace / remove) Include the previous version. |
| `waitForSync` | Block until the write is on disk. |
| `overwriteMode` | `ignore`, `replace`, `update`, `conflict` — upsert behavior. |
| `silent` | Discard the response body — saves bandwidth on large batches. |

You can let the server pick `_key`, or set it explicitly:

```php
$users->insert([ '_key' => 'alice' , 'name' => 'Alice' ]) ;
```

## Update vs Replace

```php
// PATCH — merges; untouched fields are kept.
$users->update( 'alice' , [ 'role' => 'admin' ] ) ;

// PUT — overwrites everything except _key / _id / _rev.
$users->replace( 'alice' , [ 'name' => 'Alice Doe' ] ) ;
```

Both accept the same options as `insert`. Set `returnOld: true` to get the pre-write version back.

## Remove

```php
$users->remove( 'alice' ) ;
$users->remove( 'alice' , [ 'returnOld' => true ] ) ;
```

## Truncate and drop

```php
$users->truncate() ;   // empties the collection, keeps it
$users->drop() ;       // deletes the collection server-side
```

## Batch operations

Four methods to hit the server in **one** request with a multi-document body. They each return an array of `Document` instances, one per input row, in order.

```php
$results = $users->saveAll(
    [
        [ 'name' => 'Alice' ] ,
        [ 'name' => 'Bob' ] ,
        [ 'name' => 'Carol' ] ,
    ] ,
    [ 'returnNew' => true ] ,
) ;

foreach ( $results as $doc )
{
    echo $doc->getKey() . PHP_EOL ;
}
```

| Method | Behavior |
|---|---|
| `saveAll( $documents , $options = [] )` | Insert N documents. |
| `updateAll( $patches , $options = [] )` | PATCH N — each patch must include `_key` or `_id`. |
| `replaceAll( $documents , $options = [] )` | PUT N — each doc must include `_key` or `_id`. |
| `removeAll( $selectors , $options = [] )` | Remove N — selectors are key strings or `{ _key => ... }` arrays. |

> **Per-row failures don't throw.** If one of 100 inserts conflicts, you still get back an array of 100 `Document`s — the failing row will have `error: true`, `errorNum`, and `errorMessage`. Inspect each result.

```php
foreach ( $users->saveAll( $rows ) as $i => $doc )
{
    if ( $doc->get( 'error' ) === true )
    {
        echo "row $i failed: " . $doc->get( 'errorMessage' ) . PHP_EOL ;
    }
}
```

## Bulk import (JSON Lines)

For large initial loads — tens of thousands of rows — prefer `import()`. It uses ArangoDB's `/_api/import` endpoint and is significantly faster than `saveAll()` because the body is streamed line-by-line.

```php
$result = $users->import(
    [
        [ 'name' => 'Alice' ] ,
        [ 'name' => 'Bob' ] ,
        // ... thousands more
    ] ,
    [
        'onDuplicate' => 'update' ,   // or 'replace' / 'ignore' / 'error'
        'details'     => true ,        // include per-error detail
    ] ,
) ;

echo $result->created ;       // int — inserted
echo $result->errors ;        // int — rejected rows
echo $result->updated ;       // int
echo $result->empty ;         // int — silently skipped (no _key, etc.)
echo $result->ignored ;       // int
```

| Option | Effect |
|---|---|
| `overwrite` | `true` truncates the target collection first. |
| `onDuplicate` | `error` (default), `update`, `replace`, `ignore`. |
| `details` | Include per-row error messages in `$result->details`. |
| `waitForSync` | Wait for disk persistence before returning. |

Pick the right tool:

| You need… | Use |
|---|---|
| Full result for every inserted row (especially with `returnNew`) | `saveAll()` |
| Fine-grained per-row error handling in code | `saveAll()` |
| Maximum throughput on a one-shot bulk load | `import()` |
| Just to know "how many landed, how many failed" | `import()` |

## Discovery

Three convenience helpers backed by AQL queries:

```php
$cursor = $users->byExample( [ 'role' => 'admin' ] , limit: 50 ) ;
$first  = $users->firstExample( [ 'email' => 'alice@example.com' ] ) ;   // ?Document
$all    = $users->all( limit: 100 ) ;
```

`byExample` and `all` return a `Cursor`. `Cursor` is iterable and lazy — it pulls batches from the server as you consume it.

```php
foreach ( $users->all() as $doc )
{
    echo $doc->getKey() . PHP_EOL ;
}
```

For anything beyond simple equality matching, write a real AQL query through `$db->query( ... )` — covered in a later page.

## Edges — what changes

`EdgeCollection` extends `Collection`. Everything above works. The only thing to remember: every edge must carry `_from` and `_to`.

```php
$friends = $db->edgeCollection( 'friends' ) ;
$friends->create() ;   // creates with type: EDGE

$friends->insert([
    '_from' => 'users/alice' ,
    '_to'   => 'users/bob' ,
    'since' => '2020-01-01' ,
]) ;

// Query edges by vertex:
$cursor = $friends->outEdges( 'users/alice' ) ;   // alice → ?
$cursor = $friends->inEdges ( 'users/bob' ) ;     // ? → bob
$cursor = $friends->edges   ( 'users/alice' ) ;   // either direction
```

## Error handling

All write methods throw on **transport** failure or when the server rejects the whole request:

```php
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\ConflictException ;
use oihana\arango\clients\exceptions\HttpException ;

try
{
    $users->update( 'alice' , [ 'role' => 'admin' ] ) ;
}
catch ( ConflictException $e )
{
    // Safe to retry — the revision changed under our feet.
}
catch ( HttpException $e )
{
    if ( $e->getCode() === 404 )
    {
        // Document doesn't exist.
    }
}
catch ( ArangoException $e )
{
    // Other server-side or network problem.
}
```

Per-row failures inside batch methods (`saveAll`, etc.) do **not** raise exceptions — they appear in the returned `Document` with `error: true`. See [Batch operations](#batch-operations) above.

## Where next

- [Getting started](getting-started.md) — the step-by-step intro.
- [HTTP client overview](README.md) — architecture, authentication, resilience.
- [Indexes](../indexes.md) — make your queries fast.
- [Models `Documents` and `Edges`](../models.md) — the framework's higher-level layer on top of these primitives.
