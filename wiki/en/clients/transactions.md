# Transactions

ArangoDB supports **streaming transactions**: you declare up-front which collections will be read or written, the server hands you a **transaction id**, and you run multiple operations under that id until you commit or abort. All staged writes either land atomically or are discarded.

This page covers the client surface around them.

## The model

1. Open a transaction by listing your collection access pattern — read, write, exclusive — and let the server reserve the locks.
2. Run any number of CRUD or AQL calls. The client labels each request with the transaction id automatically (header `x-arango-trx-id`).
3. Call `commit()` to apply all writes, or `abort()` to discard them. The server also auto-aborts transactions that go idle past their `lockTimeout`.

A transaction handle is **single-use**: once committed or aborted, it cannot be reused.

> The official reference is [Stream transactions](https://docs.arangodb.com/stable/develop/transactions/stream-transactions/) — worth bookmarking when you hit edge cases.

## The auto-commit helper — preferred

For 95% of cases, use `withTransaction()`. It begins, calls your callback, and commits on success or aborts on exception.

```php
$result = $db->withTransaction(
    function( Transaction $trx ) use ( $db )
    {
        $db->collection( 'users'  )->insert([ 'name' => 'Alice' ]) ;
        $db->collection( 'audits' )->insert([ 'action' => 'created' ]) ;

        return 'ok' ;
    } ,
    write : [ 'users' , 'audits' ] ,
) ;

echo $result ;   // 'ok' — whatever the callback returned
```

Inside the callback you do not need to call `$trx->commit()` — the helper owns the lifecycle. Throwing from the callback triggers `abort()` (best-effort) and re-throws.

You can mix and match access modes:

```php
$db->withTransaction(
    $callback ,
    write     : [ 'orders' ] ,
    read      : [ 'inventory' ] ,
    exclusive : [ 'sequence' ] ,
    options   : [
        'waitForSync'        => true ,
        'lockTimeout'        => 60 ,   // seconds
        'maxTransactionSize' => 1_000_000 ,
    ] ,
) ;
```

At least one of `write` / `read` / `exclusive` must be non-empty — the server rejects empty transactions.

## Manual control — `beginTransaction`

When you need to hold a transaction across event-loop boundaries or commit conditionally:

```php
$trx = $db->beginTransaction(
    write : [ 'users' ] ,
    read  : [ 'roles' ] ,
) ;

try
{
    $trx->step( function() use ( $db )
    {
        $db->collection( 'users' )->insert([ 'name' => 'Bob' ]) ;
    } ) ;

    if ( $someCondition )
    {
        $trx->commit() ;
    }
    else
    {
        $trx->abort() ;
    }
}
catch ( \Throwable $e )
{
    $trx->abort() ;
    throw $e ;
}
```

`step( callable $cb )` is the only way to make plain `Collection`/`Database` calls participate in the transaction. Inside the callback, the transaction id is installed in the transport, so every request carries the right header automatically — you don't need to plumb the id through your code.

Outside `step()`, regular calls run **without** the transaction id and target the database directly.

## The `Transaction` handle

| Property / method | What it gives you |
|---|---|
| `$trx->database` | The parent `Database`. |
| `$trx->id` | Server-assigned id (URL-safe). |
| `$trx->status() : string` | Current state — `RUNNING`, `COMMITTED`, or `ABORTED`. Hits the server. |
| `$trx->exists() : bool` | `true` if the server still knows about the transaction. Treats 404 as `false`. |
| `$trx->commit() : string` | Commits and returns the terminal status. Throws `ArangoException` on failure. |
| `$trx->abort() : string` | Aborts and returns the terminal status. |
| `$trx->step( callable $cb ) : mixed` | Runs `$cb` with the transaction id active. The return value is propagated. |

The `TransactionStatus` enum lists the three possible states:

```php
use oihana\arango\clients\transaction\enums\TransactionStatus ;

TransactionStatus::RUNNING ;
TransactionStatus::COMMITTED ;
TransactionStatus::ABORTED ;
```

## Inspecting and recovering transactions

Two helpers on `Database`:

```php
// List active transactions (admin / diagnostics).
$active = $db->listTransactions() ;
foreach ( $active as $entry )
{
    echo $entry[ 'id' ] . ' — ' . $entry[ 'state' ] . PHP_EOL ;
}

// Wrap a known id without hitting the server.
$trx = $db->transaction( 'abc-123' ) ;

if ( $trx->exists() )
{
    $trx->commit() ;
}
```

Use case: if a worker crashes mid-transaction, you can hand the id to a cleanup job that explicitly aborts the leftover. The server will eventually time out on its own, but a clean abort releases locks instantly.

## Options reference

`$options` accepted by `beginTransaction()` and `withTransaction()`:

| Key | Type | Effect |
|---|---|---|
| `waitForSync` | `bool` | Wait for disk persistence at commit time. |
| `allowImplicit` | `bool` | Allow reading from collections not declared in `read` (defaults to `false`). |
| `lockTimeout` | `int` | Seconds before the server may abort the transaction for inactivity. |
| `maxTransactionSize` | `int` | Maximum size (bytes) of staged writes. Useful guardrail. |
| `skipFastLockRound` | `bool` | Skip the cluster's fast lock pre-check (advanced). |
| `allowDirtyRead` | `bool` | Permit dirty reads inside the transaction. |

## What you cannot do inside a transaction

- Mix transaction steps from different `Database` instances.
- Open another transaction inside a `step()` callback (nesting is not supported).
- Use the same `Transaction` handle after `commit()` or `abort()` — get a fresh one.
- Run AQL queries that touch collections you didn't declare unless `allowImplicit: true`.

## Where next

- [AQL queries and Cursors](aql.md) — run AQL queries inside a transaction.
- [Graphs](graphs.md) — multi-edge writes are a natural fit for transactions.
- [HTTP client overview](README.md) — architecture and configuration.
