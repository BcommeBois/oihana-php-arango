# oihana/php-arango — ArangoDB framework for PHP

![Language](https://img.shields.io/badge/language-English-blue)

`oihana/php-arango` is a PHP library that streamlines working with [ArangoDB](https://arangodb.com): native HTTP client (Guzzle), composable AQL builder, high-level models (`Documents`, `Edges`) built by trait composition, Slim CRUD controllers, Casbin RBAC adapter, and Symfony Console commands.

![Oihana PHP Arango](https://raw.githubusercontent.com/BcommeBois/oihana-php-arango/main/assets/images/oihana-php-arango-logo-inline-512x160.png)

## Who this documentation is for

PHP developers building an API or service on top of ArangoDB who want to:

- avoid hand-written AQL via `sprintf` — composable functional helpers, zero *magic strings*;
- quickly expose full HTTP CRUD routes (filtering, pagination, search, projection, *skins*) without re-inventing the model layer for each resource;
- integrate ArangoDB into a PHP-DI container, a Slim application, and Symfony Console commands with a consistent end-to-end API.

## Quick start

```php
use oihana\arango\enums\AQL      ;
use oihana\arango\enums\Arango   ;
use oihana\arango\enums\Filter   ;
use oihana\arango\models\Documents ;

$users = new Documents( $container ,
[
    AQL::COLLECTION => 'users'              ,
    AQL::DATABASE   => 'default'            ,
    AQL::SCHEMA     => User::class          ,
    AQL::FIELDS     =>
    [
        Prop::_KEY  => Filter::DEFAULT ,
        Prop::EMAIL => Filter::DEFAULT ,
    ] ,
]) ;

$list  = $users->list( [ Arango::LIMIT => 50    ] ) ;
$first = $users->get ( [ Arango::ID    => 'abc' ] ) ;
```

For details (instantiating the `ArangoDB` client, query options, projection, edges, Slim controllers, CLI commands), see the table of contents below.

## Table of contents

### Getting started — [`getting-started/`](getting-started/)

- [Introduction](getting-started/introduction.md) — what ArangoDB is, why the technology matters, the `oihana` philosophy, and why this library exists.
- [Dependencies](getting-started/dependencies.md) — required `oihana/php-*` packages, *namespace* → package mapping, minimal `composer require` snippet for standalone use.
- [Glossary](getting-started/glossary.md) — key framework terms: *bind variable*, *document reference*, *skin*, *facet*, *traversal*, *edge*.
- [Understanding ArangoSearch](getting-started/arangosearch.md) — the search-engine primer: Analyzers, Views, `SEARCH`, scoring, what they make possible, and how every layer of the library maps onto them.

### HTTP client — [`clients/`](clients/)

- [HTTP client overview](clients/README.md) — `ArangoClient`, `Database`, architecture, when to use the client vs the façade.
- [Getting started](clients/getting-started.md) — your first `ArangoClient`, your first document, in seven small steps. **New to ArangoDB? Start here.**
- [Collections and documents](clients/documents.md) — full CRUD, batch operations, bulk JSON-Lines import, edges.
- [AQL queries and Cursors](clients/aql.md) — `aql()` helper, `AqlQuery`, lazy `Cursor` with `map` / `forEach` / `reduce` / `flatMap`.
- [Graphs](clients/graphs.md) — named *gharial* graphs, `EdgeDefinition`, type-safe edge inserts, AQL traversal.
- [Transactions](clients/transactions.md) — streaming transactions, `withTransaction()` auto-commit/abort.
- [Indexes](clients/indexes.md) — seven typed index classes (`PersistentIndex`, `GeoIndex`, `TtlIndex`, `MDIIndex`, `VectorIndex`, `InvertedIndex`, `FulltextIndex`).
- [ArangoSearch](clients/arangosearch.md) — analyzers and views for full-text search.
- [Resilience and authentication](clients/resilience.md) — auth modes and 401 auto-refresh, retry policy, multi-host failover, timeouts.

### AQL — [`aql/`](aql/)

- [Building an AQL query step by step](aql/aql-building-queries.md) — `FOR → FILTER → SORT → LIMIT → RETURN` chain, with diagram and examples.
- [AQL operations `db/operations/`](aql/aql-operations.md) — the 21 native operations (`aqlFor`, `aqlFilter`, `aqlReturn`, `aqlInsert`, `aqlTraversal`, ...).
- [Operators `db/operators/`](aql/aql-operators.md) — the 42 comparators (logical, quantified, *range*, *ternary*).
- [String functions](aql/aql-functions-strings.md) — 37 string AQL functions.
- [Date functions](aql/aql-functions-dates.md) — 30 date AQL functions.
- [Numeric functions](aql/aql-functions-numerics.md) — 35 numeric AQL functions (incl. vector distances).
- [Array functions](aql/aql-functions-arrays.md) — 19 array AQL functions.
- [Bit functions](aql/aql-functions-bit.md) — 12 bitwise AQL functions (logic, shifts, popcount, bitstrings).
- [Geospatial functions](aql/aql-functions-geo.md) — 17 geolocation AQL functions (points, polygons, distances, predicates).
- [ArangoSearch functions](aql/aql-functions-search.md) — 11 search AQL functions (context, fuzzy/phrase filtering, BM25/TF-IDF scoring).
- [Document and check functions](aql/aql-functions-checks.md) — 45 functions: *type checks*, *casts*, document operations (the full `documents/` set), database info.

### Db layer — [`db/`](db/)

- [Db layer overview](db/README.md) — the `ArangoDB` façade, when to use it vs the HTTP client, source map of the `db/` folder.
- [Quickstart `ArangoDB`](db/quickstart.md) — instantiate the façade, configure (`ArangoConfig` keys, DI), execute AQL, hydrate results, manage collections and indexes.
- [AQL helpers `db/helpers/`](db/helpers.md) — `aqlExpression`, `aqlDocument`, `aqlValue`, *field builders* and friends.
- [Conditional fields `Field::WHEN`](db/conditional-fields.md) — guard a projected value behind a condition (`cond ? value : else`), AND/OR/NOT groups, `alt` on operands.
- [Bind variables `db/binds/`](db/binds.md) — `aqlBind`, validation and formatting of injected values.
- [HTTP filters `?filter=`](db/filter.md) — `?filter=` syntax, operators (`eq`, `ne`, `like`, `in`, ...), `alt` transformations, chaining, `FilterType::*`.
- [Internal filtering — `AQL::CONDITIONS` + `AQL::BINDS`](db/filter-internal.md) — server-only conditions, `FilterType::VIRTUAL`, URL vs internal decision rule.

### Options and configuration

- [AQL options reference](options.md) — `QueryOptions`, `InsertOptions`, `UpdateOptions`, `TraversalOptions`, index options, serialization.
- [Enums reference](enums.md) — `Operator`, `Comparator`, `Clause`, `Logic`, `Node`, `Traversal`, `IndexType`, `DateUnit`.

### Business layer

- [`Documents` and `Edges` models](models.md) — trait architecture, full `AQL::*` keys catalog, CRUD methods, lifecycle hooks, cascade via *signals*.
- [Signals & cascade](lifecycle-signals-cascade.md) — the 6 lifecycle signals (`before*`/`after*`) and the delete cascade: automatic edge purge + directional purge of linked documents (`Purge::INBOUND`/`OUTBOUND`/`BOTH`).
- [Field projection](projection.md) — `Field::SKINS`, `AQL::SKIN_FIELDS`, `AQL::REQUIRES`, `Field::ALTERS`, authorizer wired automatically by the base.
- [Edge and join projection](edges-joins-projection.md) — `AQL::EDGES`, `AQL::JOINS`, hierarchical traversals, `Filter::WRAP`, `AQL::SKIN` (cycles).
- [Indexes and collection management](indexes.md) — `CollectionManagementTrait`, index types (`Persistent`, `TTL`, `Geo`, `MDI`, `Vector`).

### Slim controllers — [`controllers/`](controllers/)

- [Controllers overview](controllers/README.md) — `DocumentsController`, `EdgesController`, `PropertyController`, DI injection, `beforeModelCall` / `afterModelCall` hooks, `InjectFilterTrait`.
- [Payloads](controllers/payloads.md) — HTTP *body* extraction, `AQLType` catalog, pre-extraction i18n validation, `COMPRESS` on PATCH.
- [Rules](controllers/rules.md) — validation after payload, `Arango::RULES` + `CUSTOM_RULES`, `rules() / min() / max() / between()` helpers, vendor + project catalogs, 422 format.
- [Skins](controllers/skins.md) — output projection, catalog of the 12 canonical *skins*, `Skin::INTERNAL` strictly server-only.
- [Capabilities](controllers/capabilities.md) — fine gating on a parameter or field **value**, 7 Capability traits, *authorizer* injection pattern toward the model.

### CLI and testing

- [Symfony Console commands](commands.md) — `DocumentsCommand` and its actions (`insert`, `upsert`, `harvest`, `list`, `count`, `get`, `update`, `replace`, `delete`, `truncate`).
- [Maintenance command `command:arangodb`](commands/arangodb.md) — `dump` / `restore` / `collections` / `views` / `doctor` / `migrate`, and the [Dump / restore strategies](commands/dump-restore-strategies.md) page (ready-to-use recipes).
- [Live smoke tests](testing.md) — `./bin/console.php arango:test:clients` (low-level library) and `./bin/console.php arango:test:facade` (`ArangoDB` façade), run against an ephemeral database so production is never touched.

### Specialized modules

- [Casbin RBAC adapter](casbin.md) — `ArangoCasbinAdapter`, *batch* and *filtered* adapters, edge → *policy* synchronization, known pitfalls.
- [Root helpers `oihana\arango\helpers`](helpers.md) — sort grammar, identifier parsing, `_rev` revision encoding.

### Cross-cutting

- [Tips and pitfalls](tips.md) — golden rules to follow; page grown over time from incidents.
- [Roadmap](roadmap.md) — where the library stands and what is planned/under consideration.

## Source code

The framework code lives under [`src/oihana/arango/`](../../src/oihana/arango/).

## See also

- [Packagist `oihana/php-arango`](https://packagist.org/packages/oihana/php-arango) — the package page.
- [Tips & best practices](tips.md) — conventions and common pitfalls.
