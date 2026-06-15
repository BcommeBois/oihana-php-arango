# Indexes and collection management

This page covers **collection and index management** on the framework side: how to create, drop, inspect; which index type to choose for the need; and the recommended *lazy* declaration pattern via `AQL::INDEXES` on a model.

The detail of **properties** of each `*IndexOptions` class is in the [AQL options reference](options.md#index-options--optionsindexes). The detail of **methods** `collection*` and `*Index` is in the [Quickstart `ArangoDB`](db/quickstart.md#manage-collections). This page focuses on **how to choose** and **how to deploy**.

## Choose an index type

ArangoDB exposes six index types usable by the framework. The right choice depends on access pattern, volume, and uniqueness constraints.

| Type | When to use | Typed fields | Caveat |
|---|---|---|---|
| `persistent` | Lookups by equality or range on a field. Uniqueness constraints (email, business identifier). | scalars, dates | Blocks writes during creation (unless `inBackground: true`). |
| `ttl` | Automatic document expiration (sessions, tokens, caches). | one date or timestamp field | The document is removed as soon as `field < now - expireAfter`. |
| `geo` | Geospatial queries (distance, *within bounding box*). | `[lat, lng]` or GeoJSON coordinates | Different from `geo_legacy`; use `geoJson: true` for the standard format. |
| `mdi` | Multi-dimensional lookups (timestamp + status + region simultaneously). | several fields | Complex to size; measure before adopting. |
| `vector` | Similarity search (*embeddings*, *cosine*). ArangoDB 3.12+. | float-array field | Requires Enterprise edition for some Faiss features. |
| `fulltext` *(deprecated)* | Simple text search. | one string field | Replaced by ArangoSearch views — avoid for new features. |

For advanced text search (linguistic analyzers, BM25, facets), prefer **ArangoSearch views** rather than a `fulltext` index. The client layer wraps both analyzers and views — see [clients/arangosearch.md](clients/arangosearch.md). For full-text on a **single** collection, the client layer also exposes an `InvertedIndex` ([clients/indexes.md](clients/indexes.md#invertedindex--modern-full-text-310)) that is not yet available on the `db/` façade.

## Management methods

`ArangoDB` exposes four methods for indexes, via [`CollectionManagementTrait`](../../src/oihana/arango/db/traits/CollectionManagementTrait.php).

| Method | Description |
|---|---|
| `createIndex( $collection , $options )` | Creates an index. Accepts a raw array or an `IndexOptions` instance (auto-serialized). Returns the server definition or `null` on error. |
| `dropIndex( $collection , $indexHandle )` | Drops an index by its *handle* (of the form `collection/index-id`). |
| `getIndex( $collection , $indexId )` | Returns an index definition. |
| `getIndexes( $collection )` | Lists all indexes of a collection. |

```php
use oihana\arango\db\options\indexes\PersistentIndexOptions ;

$index           = new PersistentIndexOptions() ;
$index->fields   = [ 'email' ] ;
$index->unique   = true        ;
$index->sparse   = true        ;
$index->name     = 'idx_users_email_unique' ; // optional but recommended for debug

$db->createIndex( 'users' , $index ) ;
```

> Always name an index (`name`) — otherwise ArangoDB generates one automatically and it becomes hard to identify in *logs* and admin tools.

## Recommended pattern — *lazy* declaration via `AQL::INDEXES`

Rather than manually calling `createIndex()` in a migration script, declare indexes in the model's `AQL::INDEXES`. They are automatically created **on first instantiation** of the model (and so on the first API call in production), only if they don't already exist.

```php
use oihana\arango\db\options\indexes\PersistentIndexOptions ;
use oihana\arango\db\options\indexes\TTLIndexOptions ;

return
[
    Models::SESSIONS => fn( Container $c ) => new Documents( $c ,
    [
        AQL::COLLECTION => 'sessions'           ,
        AQL::DATABASE   => Databases::ARANGO    ,
        AQL::INDEXES    =>
        [
            // Unique tokenHash lookup
            ( function ()
            {
                $idx           = new PersistentIndexOptions() ;
                $idx->fields   = [ 'tokenHash' ] ;
                $idx->unique   = true            ;
                $idx->sparse   = true            ;
                $idx->name     = 'idx_sessions_tokenHash' ;
                return $idx ;
            } )() ,

            // Automatic expiration
            ( function ()
            {
                $ttl              = new TTLIndexOptions() ;
                $ttl->fields      = [ 'expiresAt' ] ;
                $ttl->expireAfter = 0               ;
                $ttl->name        = 'idx_sessions_ttl' ;
                return $ttl ;
            } )() ,
        ] ,
    ]) ,
] ;
```

`AQL::INDEXES` expects a **list** (`IndexOptions[]` or raw definitions). As a convenience a **single** `IndexOptions` is accepted in place of a one-element list (a raw array always stays the list) — normalization is centralized in `ArangoTrait::initializeIndexes()`, so every consumer (lazy provisioning, `doctor`) always sees an `IndexOptions[]`.

Advantages:

- **No separate migration script** — indexes follow the model.
- **Idempotent** — if the index already exists with the same name, `createIndex()` is a *no-op*.
- **Versioned with the code** — the index definition lives in the same DI *definition* as the collection.

Limit: creating a heavy `persistent` index can **block writes** for several minutes on a large collection. For this case, pass `inBackground: true` in the options.

## Class catalog by type

Detailed in [AQL options reference — Index options](options.md#index-options--optionsindexes). Summary of available classes:

| Class | Index type produced |
|---|---|
| `IndexOptions` | Abstract base (don't instantiate directly) |
| `PersistentIndexOptions` | `persistent` |
| `TTLIndexOptions` | `ttl` |
| `GeoIndexOptions` | `geo` |
| `MDIIndexOptions` | `mdi` |
| `VectorIndexOptions` | `vector` |

All these classes:

- Inherit from `IndexOptions` (and therefore from the common properties `fields`, `name`, `inBackground`, `sparse`).
- Implement `JsonSerializable` — `json_encode($options)` produces the JSON consumed by the ArangoDB engine.
- Add type-specific properties (`expireAfter` for TTL, `geoJson` for Geo, `params` for Vector...).

## Use cases by type

### Unique lookup — `persistent` + `unique`

```php
$idx           = new PersistentIndexOptions() ;
$idx->fields   = [ 'email' ] ;
$idx->unique   = true        ;
$idx->sparse   = true        ;

$db->createIndex( 'users' , $idx ) ;
```

The `sparse: true` option excludes documents where `email` is absent — useful to not block insertion of accounts being invited where the email is not yet defined.

### Composite — `persistent` multi-field

```php
$idx           = new PersistentIndexOptions() ;
$idx->fields   = [ 'status' , 'createdAt' ] ; // order matters
$idx->name     = 'idx_orders_status_created' ;

$db->createIndex( 'orders' , $idx ) ;
```

The field order determines which queries benefit from the index. An index on `[status, createdAt]` speeds up `FILTER status == @s AND createdAt > @d` but not `FILTER createdAt > @d` alone (prefix lookups only).

### Automatic expiration — `ttl`

```php
$ttl              = new TTLIndexOptions() ;
$ttl->fields      = [ 'expiresAt' ] ;
$ttl->expireAfter = 0               ; // expires as soon as expiresAt < now
$ttl->name        = 'idx_sessions_ttl' ;

$db->createIndex( 'sessions' , $ttl ) ;
```

The field must be a Unix timestamp (in seconds) or an ISO 8601 date. The expiration scan runs periodically server-side (every 30 seconds by default).

### Geospatial — `geo` + GeoJSON

```php
$geo          = new GeoIndexOptions() ;
$geo->fields  = [ 'location' ] ;
$geo->geoJson = true            ;
$geo->name    = 'idx_places_geo' ;

$db->createIndex( 'places' , $geo ) ;
```

Documents:

```json
{ "name": "Eiffel Tower", "location": { "type": "Point", "coordinates": [2.2945, 48.8584] } }
```

Typical query:

```aql
FOR p IN places
  FILTER GEO_DISTANCE([2.3522, 48.8566], p.location) < 5000  // < 5 km from Paris
  RETURN p
```

### Similarity search — `vector`

```php
$vec          = new VectorIndexOptions() ;
$vec->fields  = [ 'embedding' ] ;
$vec->params  =
[
    'dimension'    => 768          ,
    'metric'       => 'cosine'     ,
    FaithParam::N_LISTS => 1000     ,
] ;
$vec->name = 'idx_documents_vector' ;

$db->createIndex( 'documents' , $vec ) ;
```

The dimension must exactly match the vector size. The metric (`cosine`, `l2`, `inner_product`) depends on the *embedding* model used to generate the vector.

## Runtime inspection

```php
// List all indexes of a collection
$indexes = $db->getIndexes( 'users' ) ;

foreach ( $indexes as $idx )
{
    echo $idx[ 'id' ] . ' (' . $idx[ 'type' ] . ')' . PHP_EOL ;
}
```

Useful for *doctor* commands or admin reports. The list always includes implicit indexes (`primary` on `_key`, `edge` on `_from`/`_to` for *edge collections*).

## Best practices

- **Always name indexes** (`name`) for debug and tracking.
- **`inBackground: true`** on production collections with frequent writes.
- **Sparse when relevant** — `sparse: true` reduces index size if many documents lack the field.
- **`unique: true` sparingly** — increases write cost (validation).
- **Monitor selectivity** via `getIndexes()` + `estimates` (option to enable on `PersistentIndexOptions`).
- **No index on never-filtered fields** — every index costs space and write performance.

## See also

- [Quickstart `ArangoDB`](db/quickstart.md#manage-indexes) — `createIndex` / `dropIndex` / `getIndex` / `getIndexes` methods.
- [AQL options reference — Index options](options.md#index-options--optionsindexes) — property details per class.
- [`Documents` and `Edges` models](models.md) — `AQL::INDEXES` key for *lazy* declaration.
- [Client-side indexes](clients/indexes.md) — the typed `readonly` index classes (`PersistentIndex`, `GeoIndex`, `TtlIndex`, `MDIIndex`, `VectorIndex`, `InvertedIndex`, `FulltextIndex`) used directly against `/_api/index`.
- [Official ArangoDB documentation — Working with indexes](https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/).
