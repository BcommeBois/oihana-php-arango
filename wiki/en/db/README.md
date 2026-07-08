# The `db/` layer — the `ArangoDB` façade

The `db/` layer sits **above** the [HTTP client](../clients/README.md) and exposes a higher-level API tailored for application code: result hydration into typed objects, exception wrapping, query preparation with sane defaults, optional PSR-3 logging, and a fluent `prepare → execute → consume` flow.

The centerpiece is the [`ArangoDB`](../../../src/oihana/arango/db/ArangoDB.php) class — a delegator around `ArangoClient` that holds query state, caches the current `Cursor`, configures batch size and runtime limits, and hydrates documents through the `SchemaResolver | Closure | string | null` family.

| Layer | What you get | When to use |
|---|---|---|
| [HTTP client](../clients/README.md) (`clients/`) | Bare-metal transport, immutable `Document`, lazy `Cursor`, raw `Database::query()`. Standalone, no PSR-11. | Scripts, workers, integration tests, custom tooling. |
| **`ArangoDB` façade (`db/`)** | Same operations, plus hydration into your classes, `prepare/execute`, cursor metadata helpers, optional logger, exception wrapping. | Domain models, controllers, services running inside a container. |

If you only need to **issue a query and read JSON**, use the client. If you want documents hydrated into `User`, `Order`, or any class you control, use the façade.

## Learn the `db/` layer progressively

| # | Page | What you'll find |
|---|---|---|
| 1 | [Quickstart `ArangoDB`](quickstart.md) | Instantiate, configure (`ArangoConfig` keys, DI), execute raw AQL, retrieve results (`getDocuments` / `getFirstResult` / `getObject` / `getResult` / `streamDocuments`), schema hydration, cursor metadata, collection and index management. |
| 2 | [AQL helpers `db/helpers/`](helpers.md) | Compose AQL text fragments — `aqlValue`, `aqlExpression`, `aqlDocument`, field builders, skin projection helpers. |
| 3 | [Bind variables `db/binds/`](binds.md) | Safe value injection — `aqlBind`, validation and formatting of placeholders. |
| 4 | [Search & filtering](search-and-filtering.md) | **Overview** of the 3 levers (`?search` / `?filter` / `?facets`): mental model, comparison table, shared foundation (`op`, `alt`, binds, security), "when to use which". |
| 5 | [HTTP search `?search=`](search/README.md) | Multi-field `LIKE` search (case-insensitive), `searchable` declaration, combining, limits (vs ArangoSearch). |
| 5b | [View search (ArangoSearch)](search/overview.md) | The `AQL::VIEW` declaration: `?search=` switches to an index-accelerated, **relevance-ranked** search (boosts, phrase bonus, fuzzy, `score` sort key, auto-provisioned View). |
| 5c | [Analyzers](analyzers.md) | The **text-preparation recipe**: built-in analyzers (`identity`, `text_fr`, …), the 4 buildable types (`Identity`/`Norm`/`Stem`/`TextAnalyzer`), the features (`BM25`/`PHRASE`/highlight), and how to create a custom analyzer the right way. |
| 5d | [Federated multi-collection search](search/federated.md) | A **single bar** over several collections (`FederatedSearch`): the "find then rebuild" approach, pagination + total, skin, and the **per-collection permission gate** (with examples). |
| 6 | [HTTP filters `?filter=`](filter.md) | `?filter=` URL syntax, comparators, `alt` transformations, chaining, `FilterType::*`. |
| 7 | [Internal filtering — `AQL::CONDITIONS` + `AQL::BINDS`](filter-internal.md) | Server-only conditions, `FilterType::VIRTUAL`, URL vs internal decision rule. |
| 8 | [HTTP facets `?facets=`](facets.md) | `?facets=` URL syntax, `Arango::FACETS` / `Facet::TYPE` declaration, type catalogue (FIELD, IN, EDGE, JOIN, *_COMPLEX, *_AGGREGATE), operators, negation, security, **facet counts `?facetCounts=`**. |
| 9 | [HTTP grouping `?groupBy=` / `?group=`](grouping.md) | `GROUP BY` via `COLLECT`: URL syntax (`?groupBy=` CSV + `?group=` JSON), `Arango::GROUP` / `Group` vocabulary, the three uses (distinct / count / aggregates), group sorting, raw `Arango::COLLECT` spec, `groupable` whitelist and security. |
| 10 | [Sorting `?sort=` / `?near=`](sort.md) | The fail-closed `AQL::SORTABLE` whitelist (three notations), `SORT_DEFAULT`, the **sort permission gate** (inherited from `$fields` or explicit — no sort oracle), the synthetic `distance` / `score` keys, and **distance sorting `?near=`** (whitelisted geo key, `GeoIndex`). |
| 11 | [Explaining and profiling queries](explain-and-profiling.md) | Typed `explain()` / `explainList()` → `ExplainResult` (optimizer rules, **which indexes the query actually uses**) and profiling via the `'profile'` option → `getProfile()` / `getStats()` → `ProfileResult` / `ExecutionStats` (scanned / filtered / time / per-phase timings). |

## The `db/` source map

The `db/` folder is sizeable — here's what lives where, with a pointer to the doc page that covers it.

| Sub-folder | What | Documented in |
|---|---|---|
| `db/helpers/` | AQL expression builders (29 files) | [`helpers.md`](helpers.md) |
| `db/binds/` | Bind variable formatters and validators (5 files) | [`binds.md`](binds.md) |
| `db/operations/` | AQL clause builders — `FOR`, `FILTER`, `RETURN`, `INSERT`, `UPSERT`, `TRAVERSE`, … (21 files) | [`../aql/aql-operations.md`](../aql/aql-operations.md) |
| `db/operators/` | Comparators and quantifiers — `allEqual`, `anyIn`, `ternary`, … (42 files) | [`../aql/aql-operators.md`](../aql/aql-operators.md) |
| `db/functions/` | AQL built-in function wrappers — strings, dates, numerics, arrays, document checks (~144 files) | [`../aql/aql-functions-strings.md`](../aql/aql-functions-strings.md) and the four sibling function pages |
| `db/options/` | Options DTOs — `QueryOptions`, `ForOptions`, `*IndexOptions`, etc. | [`../options.md`](../options.md) |
| `db/enums/` | Constants — `ArangoConfig`, `AQL`, `Clause`, `Comparator`, `IndexType`, … | [`../enums.md`](../enums.md) |
| `db/traits/` | `CollectionManagementTrait` (collection + index CRUD) | [`quickstart.md`](quickstart.md#manage-collections) |
| `db/commands/` | Façade smoke-test command `arango:test:facade` | [`../testing.md`](../testing.md) |

## See also

- [HTTP client overview](../clients/README.md) — the layer the façade sits on.
- [`Documents` and `Edges` models](../models.md) — the business layer that consumes `ArangoDB`.
- [Slim controllers](../controllers/README.md) — HTTP exposition of the model.
- [Symfony Console commands](../commands.md) — CLI exposition of the model.
- [Tips and pitfalls](../tips.md) — golden rules for production use.
