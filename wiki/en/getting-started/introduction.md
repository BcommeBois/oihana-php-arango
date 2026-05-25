# Introduction

## What is ArangoDB?

[ArangoDB](https://arangodb.com) is a **multi-model** database: a single engine stores **documents** (JSON), **graphs** (typed vertices and edges) and **key-value** pairs, and exposes the three models through a unified query language, [**AQL** (ArangoDB Query Language)](https://docs.arangodb.com/stable/aql/).

Key characteristics:

- **RocksDB** storage engine (multi-document ACID transactions, reliable persistence).
- **AQL**: declarative SQL-inspired language that expresses both document queries (`FOR doc IN users FILTER ... RETURN doc`) and graph traversals (`FOR v, e, p IN 1..3 OUTBOUND start GRAPH 'g' ...`).
- **Diverse indexes**: *persistent*, *TTL* (automatic expiration), *geo* (geospatial queries), *fulltext* (text search), *vector* (similarity search), *MDI* (multi-dimensional).
- **Horizontal cluster**: collection *sharding*, replication, *smart graphs* (Enterprise edition).
- **Foxx**: JavaScript microservices embedded in the database, executed by the V8 engine.

ArangoDB is available as Open Source (Community Edition) and as a commercial edition.

## Why ArangoDB

The main contribution of the technology fits in one sentence: **you no longer need to juggle a document store and a graph store**. Classic architectures often stack MongoDB (for documents) and Neo4j (for graphs), with two drivers, two query languages, two mental models, and the chore of keeping both worlds consistent through application code.

ArangoDB delivers both promises in a single engine:

- **One data store**: a document can be a graph vertex; the edge references documents directly through their native identifier (`_from`, `_to`).
- **One query** can mix document filtering and graph traversal.
- **ACID transactions** across multiple documents and multiple collections, including in cluster mode.
- **Competitive performance** on both models, without the cost of an abstraction layer.
- **Optional schema**: you can start without a schema, then introduce JSON Schema validators progressively.

The trade-off: the community is smaller than MongoDB's or PostgreSQL's, and the official client ecosystem is less polished — which is part of the motivation for `oihana/php-arango`.

## The `oihana/php-arango` philosophy

The framework follows five principles that thread through the whole codebase:

1. **Composable standalone functions, not a heavy ORM.** The AQL layer is made of hundreds of small autoloaded PHP functions (`aqlFor`, `aqlFilter`, `aqlReturn`, `aqlValue`, `aqlBind`, ...) that you compose. No giant `QueryBuilder` object to learn — you read the AQL produced by looking at its PHP code.
2. **Zero *magic strings*.** Every option key, every AQL operator, every filter type is exposed as a typed enum constant (`AQL::COLLECTION`, `Operator::EQ`, `Filter::DATETIME`). Raw strings (`'-' . 'created'`, `'eq'`, ...) are systematically replaced by helpers (`descKey()`, `Comparator::EQ`, ...). This discipline makes renames refactor-friendly and IDE search reliable.
3. **Fine trait composition.** A `Documents` model is not a giant class: it is built by composing about fifty single-responsibility traits (`DocumentsGetTrait`, `DocumentsInsertTrait`, `FilterTrait`, `SortTrait`, `SearchTrait`, ...). You can pick an isolated trait for a specific use case without inheriting the rest.
4. ***Container-friendly*.** Everything is designed to live behind a PSR-11 container (PHP-DI, Symfony DI, ...). Models, controllers and commands accept a `ContainerInterface` in their constructor and resolve their dependencies (ArangoDB connection, schemas, logger, signals) by service identifier.
5. **Slim and Symfony Console integration out of the box.** `DocumentsController` produces a full CRUD route in a few lines, with filtering, pagination, sorting, search, *skins* and projection. `DocumentsCommand` exposes the same operations on the CLI for *seeding*, *harvest* and maintenance.

## Why this library

The need was born from a simple observation: **the PHP ecosystem around ArangoDB is underequipped**.

- The [official driver `triagens/ArangoDb`](https://github.com/arangodb/arangodb-php) has not received a major update in years and has accumulated significant debt (no typed enums, PHP < 8 signatures, no ergonomic AQL builder, aging dependencies).
- To our knowledge, no composable AQL *query builder* exists on the PHP side. Users hand-write AQL through `sprintf`, with the injection risks and fragility this entails.
- The usual integration layers (PSR-11 DI, Slim, Symfony Console) are not provided — each project re-glues its own.
- Cross-cutting needs (projection by *skin*, *edges* and *joins* with permission gating, Casbin adapter, *signals* for relation cascade) are absent and everyone re-implements them.

`oihana/php-arango` addresses all four points. The [`clients/`](../../../src/oihana/arango/clients/) folder bundles a modern HTTP client (Guzzle, PHP 8.4+) written to the library's standards: resilient transport, retry on transient errors, cluster failover, Basic + JWT/Bearer authentication, and a surface that covers the full `arangod` REST API (documents, edges, AQL, indexes, transactions, graphs, analyzers, views).

## Audience and prerequisites

This documentation assumes the reader:

- masters PHP 8.4 or higher (systematic use of enums, *readonly properties* and *first-class callable syntax* is central to the codebase);
- has a basic understanding of ArangoDB — the **collection** concept (document or edge), the structure of a document (`_key`, `_id`, `_rev`), and the ability to read a simple AQL query;
- is comfortable with a PSR-11 container (PHP-DI is used in examples, but the code is not tied to it).

Knowledge of Slim or Symfony Console is not required: these two integrations are independent modules, the AQL layer and the models can be consumed without touching either.

## Positioning against PHP alternatives

| Solution | Status | AQL builder | DI / Slim / CLI integration | Projection / Skins | Casbin |
|---|---|---|---|---|---|
| [`triagens/ArangoDb`](https://github.com/arangodb/arangodb-php) (official) | minimal maintenance | no | no | no | no |
| Various community drivers | sporadic | no | no | no | no |
| `oihana/php-arango` | active | yes | yes | yes (`Field::SKINS`, `AQL::SKIN_FIELDS`, `AQL::REQUIRES`) | yes (`ArangoCasbinAdapter`) |

The table is not an exhaustive review but summarizes the observation that motivates the project: no PHP alternative covers the whole spectrum of needs, and many teams end up writing their own layer.

## Going further

- [Dependencies](dependencies.md) — required `oihana/php-*` packages and `composer require` snippet.
- [Glossary](glossary.md) — key terms (*bind variable*, *skin*, *facet*, *traversal*, *edge*).
- [Quickstart `ArangoDB`](quickstart.md) — instantiate the client and execute a first query.
- [Official ArangoDB documentation](https://docs.arangodb.com/stable/) — reference for AQL, indexes, cluster.
