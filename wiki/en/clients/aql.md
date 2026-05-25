# AQL queries and Cursors

Beyond simple key-based CRUD ([documents.md](documents.md)), every non-trivial read goes through an **AQL query**. This page covers how to build one safely, run it, and consume its results lazily via a `Cursor`.

It assumes you've read [Getting started](getting-started.md).

## Why not just write the AQL by hand?

You can. `Database::query()` accepts a plain string. But the moment a value in the query comes from a variable, you need **bind variables** — placeholders the server substitutes safely. Concatenating values into AQL strings is an injection bug waiting to happen.

The library offers three building blocks, from lowest to highest level:

| Tool | Use it when |
|---|---|
| Raw string + `$bindVars` array | One-off query, you control every placeholder. |
| `aql()` helper | You want safe placeholders without naming every bind manually. |
| `AqlQuery` value object | You're composing a query from fragments or storing it as a constant. |

## Bind variables: the convention

ArangoDB uses two prefixes:

- `@name` — a **value** bind. Replaced with the bound value, properly escaped.
- `@@name` — a **collection name** bind. Replaced with an unquoted collection identifier.

```php
$cursor = $db->query(
    'FOR u IN @@coll FILTER u.age > @minAge RETURN u' ,
    [ '@coll' => 'users' , 'minAge' => 18 ] ,
) ;
```

Note the key in the `bindVars` array carries **one** `@` for collection binds — the parser strips the leading `@@` to one when looking it up.

## The `aql()` helper

For ad-hoc queries, `aql()` lets you skip naming binds. It uses positional `?` placeholders like PDO.

```php
use function oihana\arango\clients\aql\helpers\aql ;

$query = aql(
    'FOR u IN users FILTER u.age > ? AND u.role == ? RETURN u' ,
    18 ,
    'admin' ,
) ;

// Equivalent to:
// new AqlQuery(
//     query    : 'FOR u IN users FILTER u.age > @value1 AND u.role == @value2 RETURN u' ,
//     bindVars : [ 'value1' => 18 , 'value2' => 'admin' ] ,
// )

$cursor = $db->query( $query ) ;
```

Sibling helpers in the same namespace:

- `aqlLiteral( string $fragment ) : AqlLiteral` — wraps a fragment that must be inlined verbatim (a keyword, a function name, a sort direction). Use sparingly — only for things that can't be passed as values.
- `join( array $fragments , string $separator = ' ' ) : AqlQuery` — merges multiple `AqlQuery` / `AqlLiteral` / scalar fragments with collision-aware bind renaming. Useful when you compose a query from reusable parts.

`aql()` does NOT support collection binds (`@@coll`). If you need a dynamic collection name, build the `AqlQuery` directly.

## The `AqlQuery` value object

```php
use oihana\arango\clients\aql\AqlQuery ;

$query = new AqlQuery(
    query    : 'FOR u IN @@coll FILTER u._key == @key RETURN u' ,
    bindVars : [ '@coll' => 'users' , 'key' => 'alice' ] ,
) ;
```

It's `readonly` — you build it once and pass it around. Its two public properties are `query` and `bindVars`.

## Running the query

```php
$cursor = $db->query( $query ) ;
```

`Database::query()` accepts either an `AqlQuery` instance or a plain string. When you pass a string, give the bind vars in the second argument; when you pass an `AqlQuery`, the second argument must be empty (the helper will throw `InvalidArgumentException` otherwise).

The third argument is an options array forwarded to the server cursor:

| Option | Type | Effect |
|---|---|---|
| `count` | `bool` | If `true`, the server returns the total result count up-front. Required to use `$cursor->count()`. |
| `fullCount` | `bool` | Returns the count **before** any `LIMIT` clause. Read it via `$cursor->getFullCount()`. |
| `batchSize` | `int` | Number of rows per network batch. The cursor will transparently fetch more batches as you iterate. |
| `ttl` | `int` | Server-side cursor lifetime, in seconds. |
| `cache` | `bool` | Reuse the AQL query results cache if applicable. |
| `memoryLimit` | `int` | Maximum memory (bytes) the query is allowed to use. |
| `options` | `array` | Nested object for advanced flags: `profile`, `maxRuntime`, `failOnWarning`, `optimizer.rules`, etc. |

```php
$cursor = $db->query(
    aql( 'FOR u IN users LIMIT @offset , @limit RETURN u' , 0 , 100 ) ,
    [] ,
    [ 'count' => true , 'fullCount' => true , 'batchSize' => 50 ] ,
) ;
```

## The `Cursor`

`Cursor` implements `IteratorAggregate` and `Countable`. It pulls batches from the server **lazily** — the next batch is fetched only when you've exhausted the current one.

### Iterate

```php
foreach ( $cursor as $row )
{
    handle( $row ) ;
}
```

Each `$row` is whatever your `RETURN` clause produces — often an array, sometimes a scalar, sometimes the raw `Document`-shaped associative array.

### Eager helpers

| Method | What it does |
|---|---|
| `all() : array` | Pulls every remaining batch and returns the full result set as one array. |
| `count() : int` | Total count from the server. **Requires `count: true`** at query time. |
| `getFullCount() : int` | Pre-`LIMIT` count. **Requires `fullCount: true`** at query time. Returns `0` if not requested. |
| `forEach( callable $cb ) : bool` | Calls `$cb( $row , $index , $cursor )` for every row. Return `false` from the callback to short-circuit. |
| `reduce( callable $reducer , mixed $initial = null ) : mixed` | Fold every row through `( $accumulator , $row , $index , $cursor )`. |
| `flatMap( callable $cb ) : array` | Apply `$cb` per row, then flatten one level. |

### Lazy helpers

| Method | What it does |
|---|---|
| `map( callable $cb ) : Generator` | Returns a lazy generator. Nothing happens until iteration. |
| `getIterator() : Generator` | What `foreach` calls under the hood. |
| `hasMore() : bool` | `true` while more batches remain on the server. |
| `getId() : ?string` | Server-side cursor id, or `null` once the cursor is fully consumed. |
| `getExtra() : array` | Metadata from the most recent batch (warnings, stats, profile). |
| `close() : void` | Releases the server-side cursor early. No-op if already drained. |

### A pipeline example

```php
$names = $db->query( aql( 'FOR u IN users FILTER u.active == ? RETURN u' , true ) )
    ->map  ( fn( array $u ) => $u[ 'name' ] )
    ->forEach( fn( string $name ) => echo $name . PHP_EOL ) ;
```

Or eagerly with `reduce`:

```php
$totalAge = $db->query( aql( 'FOR u IN users RETURN u.age' ) )
    ->reduce( fn( int $sum , int $age ) => $sum + $age , 0 ) ;
```

## Diagnosing a query

Two non-executing endpoints help you debug AQL without paying the cost of running it.

### `explain()` — show the execution plan

```php
$plan = $db->explain( $query ) ;

print_r( $plan[ 'plan' ][ 'nodes' ] ) ;       // execution nodes
print_r( $plan[ 'plan' ][ 'estimatedCost' ] ) ; // optimizer estimate
print_r( $plan[ 'warnings' ] ) ;
```

### `parse()` — lightweight syntax check

```php
$ast = $db->parse( 'FOR u IN users RETURN u' ) ;

print_r( $ast[ 'collections' ] ) ;   // [ 'users' ]
print_r( $ast[ 'bindVars' ] ) ;      // referenced bind names
```

`parse()` only validates the grammar; the query is not executed and bind values are not required.

## When something goes wrong

`Database::query()` throws:

- `InvalidArgumentException` — when you pass an `AqlQuery` with non-empty bind vars (the helper considers it a programming error).
- `ArangoException` — when the server rejects the query (parse error, missing collection, type mismatch, authorization, etc.). The exception carries `errorNum` and `getCode()` for narrowing.

Inside batch iteration, transient errors (network drop, leader switch) bubble up as subclasses of `ArangoException`. Many of them set `isSafeToRetry()` to `true` — the cursor itself doesn't retry, but you can.

## Where next

- [Graphs](graphs.md) — named *gharial* graphs and traversal queries.
- [Transactions](transactions.md) — group multiple AQL queries into one atomic unit.
- [HTTP client overview](README.md) — architecture and configuration.
- [AQL functions reference](../aql/aql-functions-strings.md) — the catalog of AQL functions exposed as PHP helpers.
