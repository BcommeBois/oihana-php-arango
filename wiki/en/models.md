# `Documents` and `Edges` models

The [`src/oihana/arango/models/`](../../src/oihana/arango/models/) folder provides the **high-level layer** of the framework: two pivot classes (`Documents` and `Edges`) that expose full CRUD, filtering, pagination, sorting, search, facets, *skin* projection, *edges* and *joins* — all the richness of a REST resource in a single definition.

The classes are composed by aggregating **~50 single-responsibility traits**. Each model is instantiated with a PSR-11 container and a configuration array using the [`AQL::*`](enums.md#aql) keys, which parameterizes the target collection, projection, filters, *edges*, etc.

## Overview

| Class | Role | Extends |
|---|---|---|
| `Documents` | Generic model for an ArangoDB **document** collection. | — |
| `Edges` | Specialization for an **edge** collection (`_from` vertex → `_to` vertex). | `Documents` |

Both classes consume the `traits/` sub-folder traits massively, each covering one responsibility (`DocumentsGetTrait`, `FilterTrait`, `SortTrait`, ...). You can consume an isolated trait for a specific use case without inheriting all of `Documents` — see [Fine composition](#fine-composition--reuse-an-isolated-trait).

## The `Documents` class

### Instantiation

```php
use oihana\arango\models\Documents ;
use oihana\arango\enums\AQL ;
use oihana\arango\enums\Filter ;

$users = new Documents( $container ,
[
    AQL::COLLECTION   => 'users'                  ,
    AQL::DATABASE     => Databases::ARANGO        ,
    AQL::SCHEMA       => User::class              ,
    AQL::FIELDS       =>
    [
        Prop::_KEY    => Filter::DEFAULT  ,
        Prop::EMAIL   => Filter::DEFAULT  ,
        Prop::CREATED => Filter::DATETIME ,
        Prop::ACTIVE  => Filter::BOOL     ,
    ] ,
    AQL::FILTERS      =>
    [
        Prop::CREATED => FilterType::DATE   ,
        Prop::EMAIL   => FilterType::STRING ,
        Prop::ACTIVE  => FilterType::BOOL   ,
    ] ,
    AQL::SEARCHABLE   => [ Prop::EMAIL                          ] ,
    AQL::SORTABLE     => [ Prop::ID => Prop::_KEY , Prop::EMAIL ] ,
    AQL::SORT_DEFAULT => Prop::CREATED                            ,
]) ;
```

The container is used to resolve dependencies declared by service identifier: `DATABASE` resolves to an [`ArangoDB`](db/quickstart.md) instance, `SCHEMA` designates a mapping class, etc.

### Full `AQL::*` key catalog

| Key | Type | Role |
|---|---|---|
| `AQL::COLLECTION` | `string` | Target ArangoDB collection name. |
| `AQL::DATABASE` | `string` | DI identifier of the [`ArangoDB`](db/quickstart.md) service. |
| `AQL::SCHEMA` | `class-string` | Schema class for hydration (`Thing` or hydratable). |
| `AQL::FIELDS` | `array` | Exposed fields and their [`Filter::*`](enums.md#types) (see [Field](getting-started/glossary.md#field)). |
| `AQL::SKIN_FIELDS` | `array` | Per-skin alternative projections (`skin => fields` table, see [projection.md](projection.md#at-the-model-level--one-projection-per-skin-for-the-root)). |
| `AQL::FILTERS` | `array` | Fields filterable from URL and their `FilterType::*` (see [filter.md](db/filter.md)). |
| `AQL::SEARCHABLE` | `array` | Fields `?search=` operates on. |
| `AQL::SORTABLE` | `array` | Sort whitelist for `?sort=` (see [Sorting](db/sort.md)). |
| `AQL::SORT_DEFAULT` | `string` | Default sort in grammar format ([`sortKeys`](helpers.md)). |
| `AQL::SORT_TIEBREAK` | `string` | Criterion closing the order, appended last to every sort (see [Sorting](db/sort.md)). |
| `AQL::EDGES` | `array` | *Edge* definitions (see [edges-joins-projection.md](edges-joins-projection.md)). |
| `AQL::JOINS` | `array` | *Join* definitions (same page). |
| `AQL::RESOLVE` | `array` | Internal *edges* not exposed (used for cascade). |
| `AQL::REQUIRES` | `string\|array` | Permission required to expose an *edge*/*join*. |
| `AQL::FACETS` | `array` | Facet definitions (`?facet=`). |
| `AQL::FILLABLE` | `array` | Fields mass-assignable on insert/update. |
| `AQL::ARRAYS` | `array` | Embedded array fields and their mode/counter (see [arrays.md](db/arrays.md)). |
| `AQL::ALTERS` | `array` | Post-query transformations on returned documents. |
| `AQL::INDEXES` | `array` | Indexes to create on first instantiation (lazy). |
| `AQL::CONDITIONS` | `array` | Server-side injected AQL conditions (see [filter-internal.md](db/filter-internal.md)). |
| `AQL::BINDS` | `array` | Server-side injected *bind variables*. |

### Sorting — `AQL::SORTABLE` / `AQL::SORT_DEFAULT` / `AQL::SORT_TIEBREAK`

Sorting (`?sort=` and `?near=`) has its own **dedicated page**: [Sorting](db/sort.md). The essentials:

- **Fail-closed** — `AQL::SORTABLE` resolves each `?sort=` key to an AQL field (three mixable notations: indexed shorthand, alias, associative). A key outside the whitelist is **silently dropped**; **`null` (no whitelist) = nothing is sortable** (never "everything"). `AQL::SORT_DEFAULT` goes through the same whitelist and must therefore name declared keys.
- **Total order** — `AQL::SORT_TIEBREAK` appends, last, the criterion that **closes** the order, to every sort including an explicit `?sort=` (unless it already names it). Without it, paginating on a non-unique key may serve one document twice and another never. `null` by default: the mechanism is opted into model by model.
- **Permission** — a sort key may be **gated** (`Field::REQUIRES`), inherited from the homonymous projection field or declared explicitly, so a field hidden from reading is not sortable (no sort oracle).

The three notations, the permission gate and distance sorting `?near=` are detailed in [Sorting](db/sort.md).

### Main methods

| Method | Returns | Description |
|---|---|---|
| `list( $init )` | `array` | Paginated, filtered, sorted list. |
| `get( $init )` | `?object` | Single document by key. |
| `last( $init )` | `?object` | Last document according to `SORT_DEFAULT`. |
| `count( $init )` | `int` | Number of documents matching the filters. |
| `exist( $init )` | `bool` | Document existence. |
| `insert( $init )` | `?object` | New document insertion. |
| `update( $init )` | `?object` | Partial update. |
| `replace( $init )` | `?object` | Full replacement. |
| `upsert( $init )` | `?object` | Insert or update depending on existence. |
| `repsert( $init )` | `?object` | Insert or replace depending on existence. |
| `delete( $init )` | `null\|array\|object` | Document removal (cascade on *edges* if declared). |
| `truncate()` | `void` | Empties the collection. |
| `stream( $init )` | `Generator` | Lazy iteration (useful for large volumes). |
| `foundRows()` | `int` | *Full count* after a paginated `list`. |

Each method accepts an `$init` array with `Arango::*` keys (different from the definition's `AQL::*`) that overrides behavior per call: `Arango::ID`, `Arango::LIMIT`, `Arango::OFFSET`, `Arango::SKIN`, `Arango::SORT`, `Arango::FILTER`, etc.

```php
$users->list([
    Arango::LIMIT  => 50              ,
    Arango::OFFSET => 0               ,
    Arango::SORT   => '-created,email' ,
]) ;

$users->get( [ Arango::ID => 'abc' , Arango::SKIN => Skin::FULL ] ) ;

$total = $users->count( [ Arango::FILTER => '{"key":"active","val":true}' ] ) ;
```

### Cursor options — `Arango::CURSOR_OPTIONS`

When a model reads, it opens a **cursor** on the server: ArangoDB computes the result and hands it
back in batches. A few settings of that cursor belong to the caller — the batch size, how long the
cursor lives between two batches, a memory or time ceiling, the streaming mode. They go in
**`Arango::CURSOR_OPTIONS`**, an array keyed by the `CursorField` constants, and `list()`, `get()`,
`count()` and `stream()` honour them the same way. Without the key, every read sends exactly what it
sent before.

**The setting.** An `events` collection — an event log of two million records. Every night, a job
walks it with `stream()` and writes each event to an export file. The application configuration sets
`batchSize = 80000`.

| option | constant | type | what it sets |
|---|---|---|---|
| `batchSize` | `CursorField::BATCH_SIZE` | integer > 0 | the number of rows per batch |
| `ttl` | `CursorField::TTL` | integer > 0 | the seconds the cursor survives **between two batches** |
| `memoryLimit` | `CursorField::MEMORY_LIMIT` | integer > 0 | the memory ceiling of the query, in bytes |
| `maxRuntime` | `CursorField::MAX_RUNTIME` | number > 0 | the time ceiling of the query, in seconds |
| `stream` | `CursorField::STREAM` | boolean | the server computes each batch when it is pulled, instead of everything up front |

#### Bounding what PHP holds in memory

**The situation.** The nightly job grows with every export. With `batchSize = 80000`, its batches are
huge: `stream()` hands the rows over one by one, but the batch they come from stays whole in memory
while it is walked.

```php
foreach ( $events->stream( [ Arango::CURSOR_OPTIONS => [ CursorField::BATCH_SIZE => 1000 ] ] ) as $event )
{
    $export->write( $event ) ;
}
```

PHP now holds a thousand records at a time. The configured value is only a default: the caller's
option replaces it, and the library fills only what the caller left out (`batchSize`, `maxRuntime`).

#### Letting the cursor outlive a slow loop

**The situation.** The export no longer writes to a file but to a remote service, and each write
takes time. Between two batches, the cursor waits on the server; if it waits longer than its time to
live, the server drops it, and the next batch answers **"cursor not found"**.

```php
$options = [ CursorField::BATCH_SIZE => 1000 , CursorField::TTL => 600 ] ;

foreach ( $events->stream( [ Arango::CURSOR_OPTIONS => $options ] ) as $event )
{
    $remote->send( $event ) ; // ten minutes to get through a batch of a thousand
}
```

The `ttl` is set from **the time spent processing one batch**, not from the length of the whole read.

#### Letting the server compute as it goes

**The situation.** We do not want the server to prepare the two million records at once.

```php
$events->stream( [ Arango::CURSOR_OPTIONS => [ CursorField::STREAM => true , CursorField::BATCH_SIZE => 1000 ] ] ) ;
```

🚨 **Measure the streaming mode before trusting it.** It has never been measured on a heavy query: a
streaming cursor keeps its query alive on the server for the whole walk, and a read that already
exceeded a time limit in one piece may exceed it again. It never becomes a default.

A walk abandoned on the way — a `break`, an exception — **closes its cursor on the server**: the
streaming query does not stay alive until its `ttl` runs out.

#### Capping a read

**The situation.** A dashboard sorts the whole collection. A plain error is preferred to a query that
runs away.

```php
$events->list
([
    Arango::SORT           => '-date' ,
    Arango::LIMIT          => 50 ,
    Arango::CURSOR_OPTIONS => [ CursorField::MEMORY_LIMIT => 256_000_000 , CursorField::MAX_RUNTIME => 5 ] ,
]) ;
```

Beyond them, the read throws an `HttpException` (see limit 3).

#### What is refused — and why the refusal is ours

The server **says nothing** about an option it does not know, nor about a value of the wrong type:
`{"batchsize": 1000}` (wrong case), `batchSize: "1000"`, `ttl: -5` or `stream: "yes"` all run as if
the option were absent (measured on ArangoDB 3.12). A typo would become an invisible setting; the read
therefore throws a `ValidationException`:

| what the caller gives | why it is refused |
|---|---|
| an option outside the five | the server would ignore it without a word |
| a value of the wrong type, null or ≤ 0 | same |
| `query`, `bindVars` | they would **replace the query the model builds**, and every guard in it |
| `fullCount` | the read decides it, from its `LIMIT`: it feeds the total of a paginated list |
| `profile` | it goes through [`Arango::PROFILE`](db/explain-and-profiling.md) |
| `count` | no read of the model hands the cursor count back |
| `stream: true` with a `LIMIT` (`list()`, `stream()`) | see limit 2 |

#### The limits

**Limit 1 — `batchSize` bounds no memory in `list()`.** `list()` gathers every batch before returning
its array: smaller batches mean more round trips, not less memory. Only `stream()` benefits — and only
when `batchSize` is smaller than the result. *It concerns you* if you are trying to lighten a big
read: go through `stream()`.

**Limit 2 — A streaming cursor does not count beyond its `LIMIT`.** Measured on 3.12: in streaming
mode, `fullCount` stays `null`, even once the cursor is drained. A paginated list in streaming mode
would report a total of **0** without an error; the combination is therefore refused. Without a
`LIMIT`, `stream` is accepted — that is precisely the full walk of a collection.

**Limit 3 — The ceilings show up as server errors.** An exceeded `memoryLimit` throws an
`HttpException` with code 500, an exceeded `maxRuntime` one with code 410, an expired `ttl` one with
code **404** ("cursor not found") — on the next batch, in the middle of the walk. No controller reads
this key from the URL; *it concerns you* only if your application wires it into a controller:
`HttpStatusCode::fromException()` translates the exception by its code, and the client would receive
a 404 for an expired cursor.

**Limit 4 — `stream()` refuses at the first iteration.** It is a generator: nothing runs before it is
walked, neither the query nor the check of the options. A refused option shows up on the first turn
of the loop, like any other error of the read.

**Limit 5 — A memory ceiling only bites on a query that allocates.** A plain read (`FOR … RETURN`, a
count, one record by its key) uses so little that even a one-byte ceiling usually passes.
`memoryLimit` is a safeguard for the reads that sort, group or gather.

### Automatic timestamps — and how a write keeps its own dates

Every write stamps `modified` with the moment of the write, and an insert stamps `created` with it
too. **`Arango::TOUCH => false`** on `upsert()` / `repsert()` disables both stamps, on both branches
of the operation : the submitted document is stored exactly as it stands.

That is what a replication or an import needs — a record copied from another system is not
*modified* by being copied, and its dates belong to the source. Without the flag, every pass of a
replication marks its whole perimeter as changed at the same second, burying the records that
actually moved.

```php
$mirror = [ ...$record , 'created' => '2024-05-12' , 'modified' => '2025-11-03' ] ;

$model->repsert
([
    Arango::FILTER  => [ 'identifier == @identifier' ] ,
    Arango::BINDS   => [ 'identifier' => $record[ 'identifier' ] ] ,
    Arango::INSERT  => $mirror ,
    Arango::REPLACE => $mirror ,
    Arango::TOUCH   => false ,
]) ;
```

The same key already governs whether the array verbs refresh `modified` (default `true`) and
whether `updateEdgeRelation()` does (default `false`). `insert()` / `update()` / `replace()` keep
stamping unconditionally.

## The `Edges` class

Extends `Documents` with four specifics:

1. **`AQL::FROM` and `AQL::TO`** declare the vertex models on each side of the edge. Enables validation and typed queries.
2. **Dedicated traits**: `EdgesGetTrait`, `EdgesCountTrait`, `EdgesInsertTrait`, `EdgesDeleteTrait`, `EdgesPurgeTrait`, `EdgesExistTrait`, plus `EdgesFromTrait` and `EdgesToTrait` which expose the automatic cascade.
3. **Cascade by signal**: a `Documents::delete()` on a vertex emits an `afterDelete` signal captured by `EdgesFromTrait`/`EdgesToTrait`, which purge the edges pointing to the deleted vertex. No application line to write — referential integrity guaranteed.
4. **`purge()`**: bulk deletion of all edges in a collection (useful for *resets*).

```php
use oihana\arango\models\Edges ;

$userHasRoles = new Edges( $container ,
[
    AQL::COLLECTION => 'user_has_roles'   ,
    AQL::DATABASE   => Databases::ARANGO  ,
    AQL::FROM       => Models::USERS      ,
    AQL::TO         => Models::ROLES      ,
]) ;
```

## Trait architecture

The framework's core. The ~50 traits are grouped into four families, each under a `traits/` sub-folder.

### CRUD traits `documents/` (14 traits)

One trait per operation. `DocumentsMethodsTrait` is an *umbrella* that aggregates everything, consumed by the `Documents` class.

| Trait | Method exposed |
|---|---|
| `DocumentsListTrait` | `list()` |
| `DocumentsGetTrait` | `get()` |
| `DocumentsLastTrait` | `last()` |
| `DocumentsCountTrait` | `count()` |
| `DocumentsExistTrait` | `exist()` |
| `DocumentsInsertTrait` | `insert()` |
| `DocumentsUpdateTrait` | `update()` |
| `DocumentsReplaceTrait` | `replace()` |
| `DocumentsUpsertTrait` | `upsert()` |
| `DocumentsRepsertTrait` | `repsert()` |
| `DocumentsDeleteTrait` | `delete()` (with *edges* cascade) |
| `DocumentsTruncateTrait` | `truncate()` |
| `DocumentsStreamTrait` | `stream()` |
| `DocumentsMethodsTrait` | *Aggregates the above* |

### CRUD traits `edges/` (9 traits)

Target the specifics of an edge collection (`_from`/`_to` vertices).

| Trait | Method exposed |
|---|---|
| `EdgesGetTrait` | `get()` adapted to edges |
| `EdgesCountTrait` | `count()` with `_from`/`_to` filter |
| `EdgesExistTrait` | `exist()` |
| `EdgesInsertTrait` | `insert()` with vertex validation |
| `EdgesDeleteTrait` | `delete()` |
| `EdgesPurgeTrait` | `purge()` (bulk delete) |
| `EdgesFromTrait` | Source vertex `afterDelete` hook |
| `EdgesToTrait` | Target vertex `afterDelete` hook |
| `EdgesTrait` | *Umbrella* |

### *Query builders* traits `queries/` (6 traits)

Generate the AQL text of a read operation. Independent from the high-level model: you can consume them in a custom service or controller to produce AQL without going through `Documents`.

| Trait | Produces |
|---|---|
| `ListQueryTrait` | Query `FOR ... [FILTER ...] [SORT ...] [LIMIT ...] RETURN ...` |
| `GetQueryTrait` | Single-document retrieval query |
| `LastQueryTrait` | Variant with reverse sort + `LIMIT 1` |
| `CountQueryTrait` | Variant with `COLLECT WITH COUNT INTO ...` |
| `ExistQueryTrait` | Optimized for existence (`LIMIT 1 RETURN 1`) |
| `UpsertQueryTrait` | Parameterized `UPSERT` query |

### AQL composition traits `aql/` (10 + 22 sub-traits)

The big functional block: each trait brings one capability (filtering, sorting, search, projection, *binds*, ...).

| Trait | Brings |
|---|---|
| `FieldsTrait` | Builds the `RETURN { ... }` by routing each field to its [*field builder*](db/helpers.md#field-builders--fields-sub-folder). |
| `FilterTrait` | Converts `?filter=` JSON to `FILTER ...` AQL with *binds*. |
| `SortTrait` | Converts `?sort=` text grammar to `SORT ...`. |
| `SearchTrait` | Converts `?search=` to a multi-field `LIKE` filter (case-insensitive) over the `AQL::SEARCHABLE` fields. |
| `LimitTrait` | Pagination `LIMIT offset, count`. |
| `BindTrait` | Centralized *bind variable* accumulation. |
| `FacetTrait` | Facet generation (field aggregations). |
| `PrepareDocumentTrait` | Document validation and normalization before insert/update. |
| `ActiveTrait` | Helper to filter on the `active` field. |

**`aql/filters/` sub-traits** (8 traits): route filters by type — `HasFilterString`, `HasFilterNumber`, `HasFilterDate`, `HasFilterBoolean`, `HasFilterArray`, `HasFilterDocumentation`, `HasFilterConditions`, `HasHierarchicalFilter`.

**`aql/facets/` sub-traits** (7 traits) and **`aql/fields/`** (7 traits): specializations for facet and projection richness.

## Fine composition — reuse an isolated trait

The point of fine composition: you can inherit from a trait without consuming the whole `Documents` machinery. Example — a *batch* service that just needs to produce a `LIST` query:

```php
use oihana\arango\models\traits\queries\ListQueryTrait ;

class UserStatsBatch
{
    use ListQueryTrait ;

    public function build( array $filters ) : string
    {
        return $this->buildListQuery
        ([
            AQL::COLLECTION => 'users'   ,
            AQL::CONDITIONS => $filters  ,
        ]) ;
    }
}
```

No dependency on `Documents`, on a DI container, on an `ArangoDB`. Just generated AQL.

## DI integration

A typical convention: one file per model under `api/definitions/@arango/`. Each file returns an array of DI definitions.

```php
// api/definitions/@arango/models/users.php
use DI\Container ;
use oihana\arango\models\Documents ;

return
[
    Models::USERS => fn( Container $c ) => new Documents( $c ,
    [
        AQL::COLLECTION   => 'users'                  ,
        AQL::DATABASE     => Databases::ARANGO        ,
        AQL::SCHEMA       => User::class              ,
        AQL::FIELDS       => [ /* ... */ ]            ,
        AQL::FILTERS      => [ /* ... */ ]            ,
        AQL::SEARCHABLE   => [ /* ... */ ]            ,
        AQL::EDGES        => [ /* ... */ ]            ,
        AQL::SORT_DEFAULT => descKey( Prop::CREATED ) ,
    ]) ,
] ;
```

On the consumer side (controller, command, other model), the model resolves by its identifier:

```php
$users = $container->get( Models::USERS ) ;
$list  = $users->list( [ Arango::LIMIT => 50 ] ) ;
```

The recommended pattern is to keep *definitions* **flat** (one file = one model = one DI service) — composition happens by reference in `AQL::EDGES` and `AQL::JOINS`, never by inheritance between definition files.

## Lifecycle and hooks

CRUD operations go through lifecycle hooks consumable by subclassing or controller *traits*:

| Hook | Phase | Typical use |
|---|---|---|
| `beforeModelCall( $request , array &$init )` | Before each controller CRUD operation. | Filter injection, custom *authorizer* (the default is already posed by the base), cross-cutting validation. |
| `afterModelCall( $request , array &$init , mixed &$result )` | After each operation. | Response enrichment, *logging*, *audit*. |
| `afterDelete` (*signal*) | After a vertex `delete()`. | Edge cascade (`EdgesFromTrait`/`EdgesToTrait`). |

`beforeModelCall`/`afterModelCall` hooks come from the `ModelCallTrait` controller-side trait (cf. [Slim controllers](controllers/README.md)). The `afterDelete` signal comes from the [`oihana/php-signals`](getting-started/dependencies.md#oihanaphp-signals) *bus*.

> The table above only shows `afterDelete`; the models actually expose **6 signal pairs** (`before*`/`after*` for insert, update, replace, upsert, delete, truncate) and a **directional delete cascade** (edge purge + linked-document purge `Purge::INBOUND`/`OUTBOUND`/`BOTH`). All of it is detailed in [Signals & cascade](lifecycle-signals-cascade.md).

## See also

- [Field projection](projection.md) — `Field::SKINS` (also honored on nested sub-fields), `AQL::SKIN_FIELDS`, `AQL::REQUIRES`, `Field::ALTERS`.
- [Edge and join projection](edges-joins-projection.md) — `AQL::EDGES`, `AQL::JOINS`, `Field::SCOPE`, `Filter::WRAP`, hierarchical traversals.
- [Search & filtering](db/search-and-filtering.md) — overview of the 3 levers (`?search` / `?filter` / `?facets`).
- [HTTP search `?search=`](db/search/README.md) — multi-field `LIKE` search.
- [HTTP filters `?filter=`](db/filter.md) — URL filter syntax, `alt` transformations, operators.
- [HTTP facets `?facets=`](db/facets.md) — multi-select and relation existentials/aggregates.
- [Embedded array fields](db/arrays.md) — `AQL::ARRAYS`, atomic mutation of an array inside a document.
- [Internal filtering](db/filter-internal.md) — `AQL::CONDITIONS` + `AQL::BINDS` for server-only conditions.
- [Slim controllers](controllers/README.md) — HTTP exposition of the model.
- [Enums reference](enums.md#aql) — `AQL`, `Filter`, `Skin`, `Traversal` consumed here.
- [Client-side CRUD](clients/documents.md) — the lower-level layer this model is built on.
- [Client-side AQL](clients/aql.md) — `aql()` helper and lazy `Cursor` semantics underneath `prepare/execute`.
- [Quickstart `ArangoDB`](db/quickstart.md) — the underlying low-level layer.
