# Indexes

Indexes are the difference between a fast query and a full collection scan. The client layer exposes seven typed index classes plus a raw escape hatch. This page covers how to create, drop, and inspect them through `Collection`.

> For the framework's higher-level `db/` layer (declaring indexes as part of a collection schema), see [Indexes and collection management](../indexes.md). The classes documented here are lower-level and used by the client directly to talk to `/_api/index`.

## Collection methods

```php
use oihana\arango\clients\collection\indexes\PersistentIndex ;

$users = $db->collection( 'users' ) ;

$users->createIndex( new PersistentIndex(
    fields : [ 'email' ] ,
    unique : true ,
    sparse : true ,
) ) ;

$all = $users->indexes() ;            // array of raw server descriptions
$one = $users->index( 'idx_email' ) ; // single index by id or name
$users->dropIndex( 'idx_email' ) ;
```

| Method | Returns | Wraps |
|---|---|---|
| `createIndex( IndexDefinition $def )` | `array` — server response with `id`, `type`, `fields`, type-specific metadata | `POST /_api/index` |
| `dropIndex( string $idOrHandle )` | `void` | `DELETE /_api/index/{handle}` |
| `index( string $idOrHandle )` | `array` — raw definition | `GET /_api/index/{handle}` |
| `indexes()` | `array` — list of raw definitions, including the built-in `primary` and `edge` indexes | `GET /_api/index?collection=…` |

`idOrHandle` accepts either a full handle (`users/idx_email`) or just the key/name part (`idx_email`) — the collection name is prepended automatically.

All four methods throw `ArangoException` on transport or server error. `index()` throws if the index doesn't exist.

## Typed index classes

All classes are `readonly` value objects implementing `IndexDefinition`. They serialize to the wire format via `toArray()`. Use named constructor arguments.

### `PersistentIndex` — the default

B-tree index. Fits the vast majority of needs: lookups, unique constraints, sort key.

```php
new PersistentIndex(
    fields       : [ 'email' ] ,
    unique       : true ,
    sparse       : true ,            // ignore documents missing the field
    name         : 'idx_email' ,
    deduplicate  : true ,            // for array-valued fields
    estimates    : true ,            // selectivity estimates for the optimizer
    cacheEnabled : false ,
    storedValues : [ 'name' ] ,       // covering index — read these without the doc
    inBackground : true ,             // build without locking the collection
) ;
```

### `GeoIndex` — geospatial

```php
// Two-field point (lat, lng)
new GeoIndex( fields: [ 'lat' , 'lng' ] ) ;

// Single GeoJSON object
new GeoIndex( fields: [ 'location' ] , geoJson: true ) ;
```

### `TtlIndex` — automatic expiration

```php
new TtlIndex(
    fields      : [ 'createdAt' ] ,    // exactly one field
    expireAfter : 86_400 ,             // seconds — documents older are removed
) ;
```

The field must hold a numeric epoch or an ISO-8601 string. Expiry is asynchronous (best-effort) — don't rely on it for security or strict deadlines.

### `MDIIndex` — multi-dimensional (3.12+)

```php
new MDIIndex(
    fields : [ 'x' , 'y' , 'z' ] ,
    unique : false ,
) ;

// Prefixed variant — speeds up queries that always filter on the prefix fields.
new MDIIndex(
    fields       : [ 'x' , 'y' ] ,
    prefixFields : [ 'tenantId' ] ,
) ;
```

`fieldValueTypes` defaults to `'double'` — the only accepted value today.

### `VectorIndex` — similarity search (3.13+)

```php
new VectorIndex(
    fields      : [ 'embedding' ] ,
    params      : [
        'dimensions' => 1536 ,
        'metric'     => 'cosine' ,    // or 'l2'
        'nLists'     => 100 ,
    ] ,
    parallelism : 4 ,
) ;
```

`params` mirrors the underlying Faiss configuration — see the [official Vector index docs](https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/vector-indexes/) for the full option list.

### `InvertedIndex` — modern full-text (3.10+)

For full-text search on one collection. For multi-collection full-text, prefer an [ArangoSearch View](arangosearch.md).

```php
new InvertedIndex(
    fields           : [ 'title' , 'body' ] ,
    analyzer         : 'text_en' ,
    features         : [ 'frequency' , 'position' , 'norm' ] ,
    includeAllFields : false ,
    primarySort      : [ [ 'field' => 'createdAt' , 'direction' => 'desc' ] ] ,
    storedValues     : [ 'authorId' ] ,
    parallelism      : 2 ,
) ;
```

### `FulltextIndex` — legacy

Deprecated since ArangoDB 3.10 in favour of `InvertedIndex`. Still present for backwards compatibility:

```php
new FulltextIndex(
    fields    : [ 'body' ] ,
    minLength : 3 ,
) ;
```

Don't use this for new code.

### `RawIndexDefinition` — escape hatch

When the index type isn't represented by a typed class — or you're testing a new ArangoDB feature ahead of the library:

```php
use oihana\arango\clients\collection\indexes\RawIndexDefinition ;

$users->createIndex( new RawIndexDefinition([
    'type'   => 'persistent' ,
    'fields' => [ 'email' ] ,
    'unique' => true ,
]) ) ;
```

The array is sent verbatim as the request body. Validation is the server's job.

## The `IndexType` enum

```php
use oihana\arango\clients\collection\indexes\enums\IndexType ;

IndexType::PERSISTENT ;
IndexType::GEO ;
IndexType::TTL ;
IndexType::MDI ;
IndexType::MDI_PREFIXED ;
IndexType::VECTOR ;
IndexType::INVERTED ;
IndexType::FULLTEXT ;     // deprecated
IndexType::PRIMARY ;       // server-managed — cannot be created manually
IndexType::EDGE ;          // server-managed — cannot be created manually
```

Use these when you read the result of `indexes()` and want to switch on the type string returned by the server.

## A complete recipe — unique email index

```php
use oihana\arango\clients\collection\indexes\PersistentIndex ;

$meta = $db->collection( 'users' )->createIndex(
    new PersistentIndex(
        fields : [ 'email' ] ,
        unique : true ,
        sparse : true ,
        name   : 'idx_email_unique' ,
    )
) ;

echo $meta[ 'id' ] ;       // 'users/12345'
echo $meta[ 'name' ] ;     // 'idx_email_unique'
echo $meta[ 'unique' ] ;   // true
```

`unique: true` enforces email uniqueness across the collection. `sparse: true` excludes documents without an `email` field from the index — without it, you'd reject documents that legitimately omit the field. The combination is the canonical pattern for optional-but-unique fields.

## Where next

- [Indexes and collection management](../indexes.md) — the higher-level `db/` layer with `IndexOptions` classes and `CollectionManagementTrait`.
- [ArangoSearch](arangosearch.md) — multi-collection full-text via analyzers and views.
- [HTTP client overview](README.md) — architecture and configuration.
