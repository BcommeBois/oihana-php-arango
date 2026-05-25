# Quickstart `ArangoDB`

The [`ArangoDB`](../../../src/oihana/arango/db/ArangoDB.php) class is the entry point of the framework's whole low-level layer. It wraps a connection to the server, exposes collection and index management via the [`CollectionManagementTrait`](../../../src/oihana/arango/db/traits/CollectionManagementTrait.php) trait, and provides raw AQL query execution.

The high-level models (`Documents`, `Edges`) and the Slim controllers all build on this class. Understanding `ArangoDB` is therefore a prerequisite for everything else.

## Direct instantiation

The simplest form — a configuration array and an optional PSR-3 *logger*:

```php
use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\ArangoConfig ;

$db = new ArangoDB
([
    ArangoConfig::ENDPOINT => 'tcp://127.0.0.1:8529' ,
    ArangoConfig::DATABASE => 'my_db'                ,
    ArangoConfig::TYPE     => 'Basic'                ,
    ArangoConfig::USER     => 'root'                 ,
    ArangoConfig::PASSWORD => 'secret'               ,
] , $logger ) ;
```

The connection is established in the constructor. Network and server errors are *caught* and written to the *logger* — the class does not throw; the caller has to check what follows. This tolerance allows building the service in a DI container without crashing at *boot* if ArangoDB is temporarily unavailable.

## Configuration — `ArangoConfig::*` keys

| Key | Type | Description |
|---|---|---|
| `ArangoConfig::ENDPOINT` | `string` | Server URL (`tcp://host:port`). |
| `ArangoConfig::DATABASE` | `string` | Target *database* name. |
| `ArangoConfig::TYPE` | `string` | Authentication scheme (`Basic`). |
| `ArangoConfig::USER` | `string` | Authentication user. |
| `ArangoConfig::PASSWORD` | `string` | Associated password. |
| `ArangoConfig::CONNECTION` | `string` | `Close` (one-shot) or `Keep-Alive` (reused). |
| `ArangoConfig::TIMEOUT` | `int` | *Connect* and *request timeout* in seconds (same value applied to both). |
| `ArangoConfig::CREATE` | `bool` | Auto-creates missing collections on insertion (default `true`). |
| `ArangoConfig::RECONNECT` | `bool` | Attempts a reconnect if a *keep-alive* connection has expired (default `true`). |
| `ArangoConfig::DEBUG` | `bool` | Enables the *legacy* driver's internal logging. |
| `ArangoConfig::BATCH_SIZE` | `int` | Default *cursor* batch size (default `10000`). |
| `ArangoConfig::MAX_RUNTIME` | `float` | Maximum query duration in seconds (`null` = no limit). |

## Instantiation via the DI container

In production, `ArangoDB` is almost always registered as a service in a PSR-11 container. The convention in a host application is one definition file per *database* under `api/definitions/@arango/`.

```php
// api/definitions/@arango/databases.php
use DI\Container ;
use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\ArangoConfig ;
use Psr\Log\LoggerInterface ;

return
[
    Databases::ARANGO => fn( Container $container ) => new ArangoDB
    (
        $container->get( 'config' )[ 'arango' ][ 'main' ] ,
        $container->get( LoggerInterface::class )
    ) ,
] ;
```

On the consumer side, the service is resolved by identifier — `Documents` and `Edges` models receive the container in their constructor and resolve the *database* via the `AQL::DATABASE` key:

```php
new Documents( $container ,
[
    AQL::COLLECTION => 'users' ,
    AQL::DATABASE   => Databases::ARANGO , // service identifier
    // ...
]) ;
```

## Execute a raw AQL query

Three steps: prepare the data, execute, read the result.

```php
$db
    ->prepare
    ([
        'query'    => 'FOR u IN users FILTER u.active == @active RETURN u' ,
        'bindVars' => [ 'active' => true ] ,
    ])
    ->execute() ;

$users = $db->getDocuments() ; // array<object>
```

`prepare()` applies the configured `BATCH_SIZE` and `MAX_RUNTIME`. `execute()` creates a new `Statement` and returns `$this`, allowing *chaining*.

### Retrieve the result

| Method | Returns | Use |
|---|---|---|
| `getDocuments()` | `array` | Full list of documents. |
| `getFirstResult()` | `mixed` | First document, or `null` if empty. |
| `getObject()` | `?object` | First document forced as `object`. |
| `getResult()` | `?array` | Raw result from the *cursor* (can be `null`). |
| `streamDocuments()` | `Generator` | Lazy iteration, document by document. |

`streamDocuments()` is preferable as soon as a large volume is suspected: the *cursor* is consumed progressively and the internal `$data` is reset at the end of the iteration.

```php
foreach ( $db->streamDocuments() as $user )
{
    handle( $user ) ;
}
```

### Schema hydration

The four `getDocuments()`, `getFirstResult()`, `getObject()` and `streamDocuments()` methods accept an optional `$schema` parameter of type `Closure | SchemaResolver | string | null`:

- `null`: the document is returned as a raw `object` (cast `(object)` if the *driver* returns an *array*).
- `string` (class name): if the class extends `org\schema\Thing`, calls `new $class( $document )`. Otherwise, reflective hydration via `hydrate()`.
- `Closure`: called with the raw document; must return either a class name (then hydrated) or the final document.
- `SchemaResolver`: polymorphic implementation (useful to discriminate the class from a document field, e.g. `@type`).

```php
$users = $db->getDocuments( User::class ) ;        // array<User>
$first = $db->getFirstResult( fn( $d ) =>          // dynamic dispatch
    $d['type'] === 'admin' ? Admin::class : User::class ) ;
```

### *Cursor* metadata

After `execute()`, three *getters* expose the metadata:

- `getCursor()` — direct access to the underlying [`Cursor`](../../../src/oihana/arango/clients/cursor/Cursor.php).
- `getFoundRows()` — *total count* (equivalent to AQL `FULL COUNT`). Requires the query to have been prepared with `fullCount: true`.
- `getExtra()` — additional metadata returned by the server (statistics, *warnings*, *plan*).

## Manage collections

`ArangoDB` consumes [`CollectionManagementTrait`](../../../src/oihana/arango/db/traits/CollectionManagementTrait.php), which exposes six idempotent methods for collections:

| Method | Description |
|---|---|
| `collectionCreate( $name , $options )` | Creates the collection if it doesn't exist. Returns `true` if created, `false` otherwise. |
| `collectionDrop( $name )` | Drops the collection if it exists. |
| `collectionExists( $name )` | `true` if the collection exists. |
| `collectionRename( $oldName , $newName )` | Renames. |
| `collectionTruncate( $name )` | Empties without dropping. |

The `$options` parameter of `collectionCreate()` accepts the ArangoDB driver keys: `type` (2 = document, 3 = edge), `waitForSync`, `keyOptions`, `numberOfShards`, `replicationFactor`, `shardKeys`, `schema`, etc. See the trait's PHPDoc for the full list.

```php
$db->collectionCreate( 'users' ) ;
$db->collectionCreate( 'user_has_roles' , [ 'type' => 3 ] ) ; // edge collection
```

## Manage indexes

Four methods for indexes, exposed by the same trait:

| Method | Description |
|---|---|
| `createIndex( $collection , $options )` | Creates an index. Accepts a raw array or an `IndexOptions` instance (serialized automatically). |
| `dropIndex( $collection , $indexHandle )` | Drops an index by its *handle*. |
| `getIndex( $collection , $indexId )` | Returns an index definition. |
| `getIndexes( $collection )` | Lists all indexes on a collection. |

```php
use oihana\arango\db\options\indexes\PersistentIndexOptions ;

$index         = new PersistentIndexOptions() ;
$index->fields = [ 'email' ] ;
$index->unique = true        ;

$db->createIndex( 'users' , $index ) ;
```

The full catalog of `*IndexOptions` classes (`Persistent`, `TTL`, `Geo`, `MDI`, `Vector`) will be detailed on the [Indexes and collection management](../indexes.md) page.

## Logger

The second constructor argument accepts a `Psr\Log\LoggerInterface`. All network errors, ArangoDB exceptions and index operation warnings are written through this *logger* — it is the framework's observability channel. If `null` is passed, errors are silently swallowed.

## See also

- [AQL helpers `db/helpers/`](../db/helpers.md) — build composable AQL expressions without `sprintf`.
- [Bind variables `db/binds/`](../db/binds.md) — inject values safely.
- [`Documents` and `Edges` models](../models.md) — high-level layer that consumes `ArangoDB` to expose full CRUD.
- [Glossary](glossary.md) — framework terms.
