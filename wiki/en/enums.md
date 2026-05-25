# Enums reference

The [`src/oihana/arango/db/enums/`](../../src/oihana/arango/db/enums/) folder groups **28 enums and traits** that expose the framework's typed constants. All follow the `oihana/php-enums` convention (`ConstantsTrait` for `keys()` / `values()` / introspection), making them registries consultable at runtime.

> Cross-cutting convention: no raw string in application code. Every configuration key, AQL operator, index type goes through an enum constant — discipline detailed in the [Introduction](getting-started/introduction.md#the-oihanaarango-philosophy).

## Summary

| Section | Enums |
|---|---|
| Central | `AQL`, `ArangoConfig` |
| AQL grammar | `Operation`, `Clause`, `Operator`, `Comparator`, `ArrayComparator`, `Logic` |
| Types | `IndexType`, `OverwriteMode`, `UpsertType`, `Traversal` |
| Temporal | `DateUnit`, `DateFormat`, `WeekDay` |
| Options | `CollectionOption`, `TraversalOption`, `TraversalOrder`, `TraversalUniqueEdges`, `TraversalUniqueVertices`, `FaithParam`, `PercentileMethod` |
| Statistics and plan | `Extra`, `Statistic`, `Node` |
| Utility traits | `ArangoConfigTrait`, `IndexOptionsTrait`, `QueryOptionsTrait` |

## Central enums

### `AQL`

The most used enum of the framework. Lists all the conventional keys consumed by operations, models, controllers and commands. It is the **shared vocabulary** between the low-level layer and the business layer.

Main keys per category:

| Family | Constants |
|---|---|
| Collection and schema | `COLLECTION`, `DATABASE`, `SCHEMA`, `DOCUMENT`, `DOC_REF`, `DOC` |
| Iteration | `IN`, `START`, `GRAPH`, `VERTEX`, `EDGE`, `PATH`, `MIN`, `MAX`, `DIRECTION` |
| Model | `FIELDS`, `FILTERS`, `FILLABLE`, `ALTERS`, `SEARCHABLE`, `SORTABLE`, `SORT_DEFAULT` |
| Relations | `EDGES`, `JOINS`, `FROM`, `TO`, `RESOLVE`, `REQUIRES` |
| Search | `SEARCH`, `FACETS` |
| Projection | `SKIN`, `SKIN_FIELDS`, `SKIN_METHODS`, `INDEXES` |
| Modification | `KEY`, `WITH`, `OPTIONS`, `CONDITIONS`, `BINDS` |
| Internal filtering | `RAW_KEYS`, `RAW_VALUES`, `USE_SPACE` |
| Authorization | `AUTHORIZER` |

Constants used in many framework examples — see [Building an AQL query step by step](aql/aql-building-queries.md), [Edge and join projection](edges-joins-projection.md).

### `ArangoConfig`

Configuration keys for the [`ArangoDB`](getting-started/quickstart.md#configuration--arangoconfig-keys) constructor. Around twenty constants that map to the connection options: `ENDPOINT`, `DATABASE`, `TYPE`, `USER`, `PASSWORD`, `CONNECTION`, `TIMEOUT`, `CREATE`, `RECONNECT`, `DEBUG`, `BATCH_SIZE`, `MAX_RUNTIME`.

## AQL grammar

| Enum | Role | Example constants |
|---|---|---|
| `Operation` | The *high-level* AQL operations | `FOR`, `FILTER`, `SORT`, `LIMIT`, `RETURN`, `INSERT`, `UPDATE`, `REPLACE`, `UPSERT`, `REMOVE`, `COLLECT`, `LET`, `SEARCH`, `PRUNE`, `WITH` |
| `Clause` | Internal sub-clauses | `INTO`, `OPTIONS`, `WITH`, `OLD`, `NEW`, `CURRENT`, `DISTINCT`, `AGGREGATE` |
| `Operator` | AQL operators and keywords | `IN`, `NOT IN`, `ALL`, `ANY`, `NONE`, `LIKE`, `DISTINCT`, `AND`, `OR`, `NOT` |
| `Comparator` | Scalar comparators | `EQUAL` (`==`), `NOT_EQUAL` (`!=`), `GREATER_THAN` (`>`), `LESS_THAN_OR_EQUAL` (`<=`), `IN`, `LIKE`, `MATCH` (`=~`) |
| `ArrayComparator` | Quantified variants | `ALL`, `ANY`, `NONE` (prefixes for the `db/operators/` functions) |
| `Logic` | Logical operators | `AND` (`&&`), `OR` (`\|\|`), `NOT` (`!`) |

These enums are consumed internally by the functions of [`db/operations/`](aql/aql-operations.md) and [`db/operators/`](aql/aql-operators.md). In application use, you rarely manipulate them directly: the functions use them to produce the right AQL text.

## Types

| Enum | Role | Constants |
|---|---|---|
| `IndexType` | ArangoDB index types | `PERSISTENT`, `TTL`, `GEO`, `FULLTEXT` (deprecated), `MDI`, `VECTOR`, `EDGE`, `PRIMARY` |
| `OverwriteMode` | `INSERT` behavior on `_key` conflict | `REPLACE`, `UPDATE`, `IGNORE`, `CONFLICT` |
| `UpsertType` | Branch taken by `UPSERT` at runtime | `INSERT`, `UPDATE` (used to analyze an upsert's result) |
| `Traversal` | Graph traversal direction | `OUTBOUND`, `INBOUND`, `ANY` |

Usage examples: see [`InsertOptions`](options.md#options-per-operation), [`aqlTraversal`](aql/aql-operations.md#aqltraversal).

## Temporal

| Enum | Role | Constants |
|---|---|---|
| `DateUnit` | Date arithmetic unit | `YEAR`, `MONTH`, `WEEK`, `DAY`, `HOUR`, `MINUTE`, `SECOND`, `MILLISECOND` |
| `DateFormat` | Format tokens for [`dateFormat()`](aql/aql-functions-dates.md#conversion-and-format) | `Y` (year), `M` (month), `D` (day), `H` (hour), `MI` (minute), `S` (second), `MS` (millisecond), plus long variants |
| `WeekDay` | Days of the week | `MONDAY`, `TUESDAY`, `WEDNESDAY`, `THURSDAY`, `FRIDAY`, `SATURDAY`, `SUNDAY` |

`DateUnit::DAY` is the default argument of [`dateAdd()`](aql/aql-functions-dates.md#arithmetic), [`dateSubstract()`](aql/aql-functions-dates.md#arithmetic), [`dateDiff()`](aql/aql-functions-dates.md#arithmetic) and [`dateTrunc()`](aql/aql-functions-dates.md#arithmetic).

## Options — `enums/options/`

Sub-folder dedicated to keys and values consumed by the [`*Options`](options.md) classes.

| Enum | Role | Main constants |
|---|---|---|
| `CollectionOption` | Keys accepted by `collectionCreate()` | `TYPE`, `WAIT_FOR_SYNC`, `IS_SYSTEM`, `KEY_OPTIONS`, `NUMBER_OF_SHARDS`, `REPLICATION_FACTOR`, `WRITE_CONCERN`, `SHARD_KEYS`, `SCHEMA` |
| `TraversalOption` | Keys of `TraversalOptions` | `ORDER`, `UNIQUE_VERTICES`, `UNIQUE_EDGES`, `BFS` (deprecated) |
| `TraversalOrder` | Traversal strategy | `BFS` (breadth), `DFS` (depth), `WEIGHTED` |
| `TraversalUniqueEdges` | Edge uniqueness policy | `NONE`, `PATH`, `GLOBAL` |
| `TraversalUniqueVertices` | Vertex uniqueness policy | `NONE`, `PATH`, `GLOBAL` |
| `FaithParam` | Faiss parameters consumed by `VectorIndexOptions` | `NLISTS`, `NPROBE`, `M`, `EFCONSTRUCTION`, etc. (vary with ArangoDB version) |
| `PercentileMethod` | Method used by [`percentile()`](aql/aql-functions-numerics.md#array-aggregation) | `RANK`, `INTERPOLATION` |

## Statistics and execution plan

Three enums describe the structure of the metadata returned by the server after a query execution. They allow analyzing `cursor->getExtra()` (see [Quickstart — cursor metadata](getting-started/quickstart.md#cursor-metadata)).

| Enum | Role |
|---|---|
| `Extra` | Root attributes of the extras returned by the *cursor* (stats, *warnings*, *plan*, *profile*). |
| `Statistic` | Individual attributes of the `stats` block (`writesExecuted`, `scannedFull`, `scannedIndex`, ...). |
| `Node` | Attributes of an AQL execution plan node (`type`, `dependencies`, `id`, `estimatedCost`, ...). |

Mostly useful for advanced *profiling* or building an inspection tool — almost never manipulated in production.

## Utility traits

Three cross-cutting traits shared between several Options classes.

| Trait | Consumed by | Provides |
|---|---|---|
| `ArangoConfigTrait` | `ArangoDB` (constructor) | Hydration and validation of `ArangoConfig::*` keys. |
| `QueryOptionsTrait` | `QueryOptions`, `ForOptions`, `CollectOptions`, etc. | Standard `JsonSerializable` serialization, `null` filtering, merging. |
| `IndexOptionsTrait` | `PersistentIndexOptions`, `TTLIndexOptions`, ... | Same as `QueryOptionsTrait` for index classes; adds AQL `type` resolution from the class. |

These traits are not an application extension point — they are consumed internally. Documented here for catalog consistency.

## Runtime inspection

All framework enums inherit from `ConstantsTrait` (via [`oihana/php-enums`](getting-started/dependencies.md#oihanaphp-enums)), which gives them two useful introspection methods:

```php
use oihana\arango\db\enums\Operation ;

Operation::keys()   ;   // [ 'FOR', 'FILTER', 'SORT', 'LIMIT', 'RETURN', ... ]
Operation::values() ;   // [ 'FOR', 'FILTER', 'SORT', 'LIMIT', 'RETURN', ... ]
Operation::has( 'FILTER' ) ;  // true
```

Useful for validation tools, debug pages, or generating drop-down lists on the admin side.

## See also

- [AQL options reference](options.md) — the `OverwriteMode`, `TraversalOrder` and other enums in context.
- [Building an AQL query step by step](aql/aql-building-queries.md) — usage of `AQL::*` and `Traversal::*` constants.
- [Dependencies](getting-started/dependencies.md#oihanaphp-enums) — `oihana/php-enums` and the `ConstantsTrait` pattern.
