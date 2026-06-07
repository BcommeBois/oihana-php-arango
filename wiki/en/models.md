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
| `AQL::FILTERS` | `array` | Fields filterable from URL and their `FilterType::*` (see [filter.md](db/filter.md)). |
| `AQL::SEARCHABLE` | `array` | Fields `?search=` operates on. |
| `AQL::SORTABLE` | `array` | URL key → AQL field mapping for `?sort=`. |
| `AQL::SORT_DEFAULT` | `string` | Default sort in grammar format ([`sortKeys`](helpers.md)). |
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
| `beforeModelCall( $request , array &$init )` | Before each controller CRUD operation. | Filter injection, *authorizer*, cross-cutting validation. |
| `afterModelCall( $request , array &$init , mixed &$result )` | After each operation. | Response enrichment, *logging*, *audit*. |
| `afterDelete` (*signal*) | After a vertex `delete()`. | Edge cascade (`EdgesFromTrait`/`EdgesToTrait`). |

`beforeModelCall`/`afterModelCall` hooks come from the `ModelCallTrait` controller-side trait (cf. [Slim controllers](controllers/README.md)). The `afterDelete` signal comes from the [`oihana/php-signals`](getting-started/dependencies.md#oihanaphp-signals) *bus*.

## See also

- [Edge and join projection](edges-joins-projection.md) — `AQL::EDGES`, `AQL::JOINS`, `Field::SKINS`, `AQL::SKIN_FIELDS`, `AQL::REQUIRES`.
- [Search & filtering](db/search-and-filtering.md) — overview of the 3 levers (`?search` / `?filter` / `?facets`).
- [HTTP search `?search=`](db/search.md) — multi-field `LIKE` search.
- [HTTP filters `?filter=`](db/filter.md) — URL filter syntax, `alt` transformations, operators.
- [HTTP facets `?facets=`](db/facets.md) — multi-select and relation existentials/aggregates.
- [Embedded array fields](db/arrays.md) — `AQL::ARRAYS`, atomic mutation of an array inside a document.
- [Internal filtering](db/filter-internal.md) — `AQL::CONDITIONS` + `AQL::BINDS` for server-only conditions.
- [Slim controllers](controllers/README.md) — HTTP exposition of the model.
- [Enums reference](enums.md#aql) — `AQL`, `Filter`, `Skin`, `Traversal` consumed here.
- [Client-side CRUD](clients/documents.md) — the lower-level layer this model is built on.
- [Client-side AQL](clients/aql.md) — `aql()` helper and lazy `Cursor` semantics underneath `prepare/execute`.
- [Quickstart `ArangoDB`](db/quickstart.md) — the underlying low-level layer.
