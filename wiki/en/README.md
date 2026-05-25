# oihana/php-arango — ArangoDB framework for PHP

![Language](https://img.shields.io/badge/language-English-blue)

`oihana/php-arango` is a PHP framework that streamlines working with [ArangoDB](https://arangodb.com): native client, composable AQL builder, high-level models (`Documents`, `Edges`) built by trait composition, Slim CRUD controllers, Casbin RBAC adapter, and Symfony Console commands.

> This documentation is **actively under construction**. The table of contents below reflects real progress: pages marked *planned* are scheduled but not yet written. See the [chantier status](#chantier-status).

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
    AQL::DATABASE   => Databases::ARANGO    ,
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

### Foundations

- [Introduction](introduction.md) — *available* — what ArangoDB is, why the technology matters, the `oihana` philosophy, and why this library exists.
- [Dependencies](dependencies.md) — *available* — required `oihana/php-*` packages, *namespace* → package mapping, minimal `composer require` snippet for standalone use.
- [Glossary](glossary.md) — *available* — key framework terms: *bind variable*, *document reference*, *skin*, *facet*, *traversal*, *edge*.

### Getting started

- [Quickstart `ArangoDB`](quickstart.md) — *available* — instantiate the client, execute raw AQL, base traits (`ArangoTrait`).
- [AQL helpers `db/helpers/`](db-helpers.md) — *available* — `aqlExpression`, `aqlDocument`, `aqlValue`, *field builders* and friends.
- [Bind variables `db/binds/`](db-binds.md) — *available* — `aqlBind`, validation and formatting of injected values.

### Building AQL queries

- [Building an AQL query step by step](aql/aql-building-queries.md) — *available* — `FOR → FILTER → SORT → LIMIT → RETURN` chain, with diagram and examples.
- [AQL operations `db/operations/`](aql/aql-operations.md) — *available* — the 21 native operations (`aqlFor`, `aqlFilter`, `aqlReturn`, `aqlInsert`, `aqlTraversal`, ...).
- [Operators `db/operators/`](aql/aql-operators.md) — *available* — the 42 comparators (logical, quantified, *range*, *ternary*).
- [String functions](aql/aql-functions-strings.md) — *available* — 37 string AQL functions.
- [Date functions](aql/aql-functions-dates.md) — *available* — 30 date AQL functions.
- [Numeric functions](aql/aql-functions-numerics.md) — *available* — 31 numeric AQL functions.
- [Array functions](aql/aql-functions-arrays.md) — *available* — 19 array AQL functions.
- [Document and check functions](aql/aql-functions-checks.md) — *available* — 28 functions: *type checks*, *casts*, document operations, database info.

### Options and configuration

- [AQL options reference](options.md) — *available* — `QueryOptions`, `InsertOptions`, `UpdateOptions`, `TraversalOptions`, index options, serialization.
- [Enums reference](enums.md) — *available* — `Operator`, `Comparator`, `Clause`, `Logic`, `Node`, `Traversal`, `IndexType`, `DateUnit`.

### Business layer

- [`Documents` and `Edges` models](models.md) — *available* — trait architecture, full `AQL::*` keys catalog, CRUD methods, lifecycle hooks, cascade via *signals*.
- [Edge and join projection](edges-joins-projection.md) — *available* — `Field::SKINS`, `AQL::SKIN`, `AQL::SKIN_FIELDS`, `AQL::REQUIRES`, `CapabilityAuthorizerTrait` pattern.
- [HTTP filters `?filter=`](filter.md) — *available* — `?filter=` syntax, operators (`eq`, `ne`, `like`, `in`, ...), `alt` transformations, chaining, `FilterType::*`.
- [Internal filtering — `AQL::CONDITIONS` + `AQL::BINDS`](filter-internal.md) — *available* — server-only conditions, `FilterType::VIRTUAL`, URL vs internal decision rule.
- [Slim controllers](controllers/README.md) — *available* — `DocumentsController`, `EdgesController`, `PropertyController`, DI injection, `beforeModelCall` / `afterModelCall` hooks, `InjectFilterTrait`.
  - [Payloads](controllers/payloads.md) — HTTP *body* extraction, `AQLType` catalog, pre-extraction i18n validation, `COMPRESS` on PATCH.
  - [Rules](controllers/rules.md) — validation after payload, `Arango::RULES` + `CUSTOM_RULES`, `rules() / min() / max() / between()` helpers, vendor + project catalogs, 422 format.
  - [Skins](controllers/skins.md) — output projection, catalog of the 12 canonical *skins*, `Skin::INTERNAL` strictly server-only.
  - [Capabilities](controllers/capabilities.md) — fine gating on a parameter or field **value**, 7 Capability traits, *authorizer* injection pattern toward the model.
- [Symfony Console commands](commands.md) — *available* — `DocumentsCommand` and its actions (`insert`, `upsert`, `harvest`, `list`, `count`, `get`, `update`, `replace`, `delete`, `truncate`).
- [Indexes and collection management](indexes.md) — *available* — `CollectionManagementTrait`, index types (`Persistent`, `TTL`, `Geo`, `MDI`, `Vector`).
- [Live smoke tests](testing.md) — *available* — `bun arango:test:clients` (low-level library) and `bun arango:test:facade` (`ArangoDB` façade), run against an ephemeral database so production is never touched.

### Specialized modules

- [Casbin RBAC adapter](casbin.md) — *available* — `ArangoCasbinAdapter`, *batch* and *filtered* adapters, edge → *policy* synchronization, known pitfalls.
- [*Legacy* ArangoDB client](client.md) — *available* — fork of the official driver, PHP 8.4 *caveat*, modern rewrite *roadmap*.
- [Root helpers `oihana\arango\helpers`](helpers.md) — *available* — sort grammar, identifier parsing, `_rev` revision encoding.

### Cross-cutting

- [Tips and pitfalls](tips.md) — *available* — golden rules to follow; page grown over time from incidents.

## Chantier status

| Phase | Description | State |
|---|---|---|
| 0 | Foundations — introduction, dependencies, glossary | *available* |
| 1 | Getting started — quickstart, `db/helpers`, `db/binds` | *available* |
| 2 | AQL core — *operations*, *operators*, *functions* | *available* |
| 3 | Options and enums | *available* |
| 4 | Business layer — models, controllers, commands | *available* |
| 5 | Specialized modules — Casbin, *legacy* client | *available* |

## Source code

The framework code lives under [`src/oihana/arango/`](../../src/oihana/arango/).

## See also

- [Packagist `oihana/php-arango`](https://packagist.org/packages/oihana/php-arango) — the package page.
- [Tips & best practices](tips.md) — conventions and common pitfalls.
