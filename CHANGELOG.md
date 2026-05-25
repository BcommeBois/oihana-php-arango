# Changelog

All notable changes to **oihana/php-arango** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial scaffold: Composer manifest, PHPUnit 12 + phpDocumentor 3 configuration, MPL-2.0 license, README, CHANGELOG, sibling-aligned folder layout (`src/`, `tests/`, `wiki/`, `assets/`, `bin/`, `configs/`, `definitions/`).
- Source code under `src/oihana/arango/` (570 PHP files) organised as:
  - `clients/` (72 files) — modern HTTP client built on Guzzle: `ArangoClient`, `Database`, `Collection`, `EdgeCollection`, `Document`, `Edge`, `Cursor` (with `map / forEach / reduce / flatMap` pipeline), `Transaction`, `Graph`, `Analyzer`, `View`, 7 typed indexes (`PersistentIndex`, `GeoIndex`, `TtlIndex`, `FulltextIndex`, `MdiIndex`, `VectorIndex`, `InvertedIndex`), Guzzle `HttpTransport` with retry policy and cluster `HostRing`. Mirrors the public surface of [`arangojs`](https://github.com/arangodb/arangojs) v9 (documents, edges, collections, AQL, indexes, transactions, graphs, ArangoSearch views and analyzers).
  - `db/` (297 files) — high-level `ArangoDB` façade, AQL helpers (`aql`, `aqlLiteral`, `aql\join`, `aqlExpression`, `aqlDocument`, `aqlFields`, …), operators (`equal`, `notEqual`, `in`, `like`, `range`, …), date/string/numeric AQL functions, definitions (collections, edges, fields).
  - `models/` (107 files) — trait-based document/edge models: `Documents`, `Edges`, list/get/insert/update/replace/delete traits with `before/after` signals.
  - `controllers/` (18 files) — `DocumentsController` + capability gating, ready for PSR-15 stacks (Slim).
  - `commands/` (36 files) — Symfony Console commands for collection management, harvesting, integrity checks, plus the live smoke tests `arango:test:clients` and `arango:test:facade`.
  - `auth/` (27 files) — Casbin policy materialization helpers + permission subject resolver. Consumes `oihana/php-auth`.
  - `casbin/` (1 file) — `ArangoCasbinAdapter` implementing all four Casbin contracts (`Adapter`, `BatchAdapter`, `FilteredAdapter`, `UpdatableAdapter`).
  - `helpers/` (8 files) — small utilities (`ascKey`, `descKey`, `parseKey`, `parseIdentifier`, `parseCollection`, `encodeRevision`, `decodeRevision`, `sortKeys`).
  - `enums/` (4 files) — typed constants (`Arango`, `ArangoConfig`, `ArangoIndex`, `ArangoIndexType`).
- Test suite under `tests/oihana/arango/` (163 PHP files): all green under PHPUnit 12 strict mode.
- Bilingual user guides under `wiki/{fr,en}/`: architecture, AQL, models, controllers, edges, indexes, transactions, graphs, analyzers, views, testing.
- CLI entry point `bin/console.php` bootstrapping a minimal PHP-DI container from `definitions/` and `configs/config.toml` so the smoke tests can run without dragging in a host application.
