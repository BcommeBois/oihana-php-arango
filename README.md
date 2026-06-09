# Oihana PHP Arango

![Oihana PHP Arango](https://raw.githubusercontent.com/BcommeBois/oihana-php-arango/main/assets/images/oihana-php-arango-logo-inline-512x160.png)

Composable PHP toolkit for [ArangoDB](https://www.arangodb.com/). Part of the **Oihana PHP** ecosystem, this package bundles a modern HTTP client, a high-level façade, document/edge models, controllers, helpers and CLI commands — everything you need to build an ArangoDB-backed application end-to-end.

[![Latest Version](https://img.shields.io/packagist/v/oihana/php-arango.svg?style=flat-square)](https://packagist.org/packages/oihana/php-arango)
[![Total Downloads](https://img.shields.io/packagist/dt/oihana/php-arango.svg?style=flat-square)](https://packagist.org/packages/oihana/php-arango)
[![License](https://img.shields.io/packagist/l/oihana/php-arango.svg?style=flat-square)](LICENSE)

## 📚 Documentation

User guides (FR + EN) live under [`wiki/`](wiki/) — start there. A full API reference can be generated locally with [phpDocumentor](https://phpdoc.org/) via `composer doc` (output under `./docs`).

A few entry points:

- [Getting started](wiki/en/getting-started/introduction.md) — concepts and dependencies.
- [Search & filtering](wiki/en/db/search-and-filtering.md) — the `?search=` / `?filter=` / `?facets=` query DSL.
- [Testing](wiki/en/testing.md) — unit tests, code coverage and the live smoke tests.
- [Contributing](CONTRIBUTING.md) — setup, conventions and the PR workflow.

## 📦 Installation

Requires [PHP 8.4+](https://php.net/releases/) and an [ArangoDB 3.11+](https://www.arangodb.com/) server. Install via [Composer](https://getcomposer.org/):

```bash
composer require oihana/php-arango
```

## ✨ What you can do

- **Talk to ArangoDB** through a modern, ready-to-use HTTP client built on Guzzle — Basic + JWT/Bearer authentication, automatic 401 refresh, cluster failover, retry on transient errors.
- **Run AQL queries** with safe placeholder binding (`aql()` helper), an `AqlBuilder` for fluent assembly, and a lazy `Cursor` supporting `map / forEach / reduce / flatMap`.
- **Manage collections, documents, edges and indexes** — full CRUD, batch operations, bulk JSON-Lines import, 7 typed index types (`PersistentIndex`, `GeoIndex`, `TtlIndex`, `FulltextIndex`, `MdiIndex`, `VectorIndex`, `InvertedIndex`).
- **Use transactions, graphs, analyzers and views** — streaming transactions with `withTransaction()` auto-commit/abort, gharial-based graphs with typed vertex/edge collections, ArangoSearch analyzers and views (full-text `SEARCH`, `PHRASE`, `BM25`).
- **Compose document models** via fine-grained traits (CRUD, AQL helpers, signals before/after CRUD).
- **Plug controllers** into any [Slim](https://www.slimframework.com/)-compatible PSR-15 stack with `DocumentsController` + capability gating.
- **Drive list queries from the URL** — a declarative `?search=`, `?filter=` and `?facets=` DSL on document models that compiles to safe, bound AQL: rich comparators, `AND`/`OR`/`NOT`, range (`between`) and array quantifiers (`quant`, `AT LEAST`), hierarchical field paths, edge/join facets, plus output-side `alt` projection transforms.
- **Run live smoke tests** against a real `arangod` via the built-in `arango:test:clients` and `arango:test:facade` console commands.

### Under the hood

- A consistent set of value objects and enums — no magic strings.
- Pure-PHP transport based on [GuzzleHttp](https://github.com/guzzle/guzzle) v7.
- Helpers for [PSR-11 Container](https://www.php-fig.org/psr/psr-11/) wiring.
- Hydration delegated to [`oihana/php-reflect`](https://github.com/BcommeBois/oihana-php-reflect) — the client returns array data by default, the high-level façade hydrates into typed objects.
- Casbin RBAC adapter for ArangoDB included (`oihana\arango\casbin\ArangoCasbinAdapter`).

## ✅ Running tests

Run all tests:

```bash
composer test
```

Run a specific test file:

```bash
composer test ./tests/oihana/arango/SomeTest.php
```

### Code coverage

Measure how much of `./src` the suite exercises (requires Xdebug or PCOV):

```bash
composer coverage       # text + Clover + HTML report under build/coverage/
composer coverage:md    # readable Markdown summary at build/coverage/COVERAGE.md
```

The `build/` output is gitignored — regenerate it on demand rather than committing a snapshot. See [the testing guide](wiki/en/testing.md) for the full workflow.

### Live smoke tests against a real arango database

The package ships with two end-to-end smoke tests that exercise every public surface against a live ArangoDB server. They operate on an ephemeral database that is created and dropped per run, so production data is never touched.

```bash
# Copy the example config and adjust the [arango] section
cp configs/config.example.toml configs/config.toml

# Run the full smoke suite for the new clients/ HTTP library
./bin/console.php arango:test:clients

# Run the smoke suite for the high-level ArangoDB façade
./bin/console.php arango:test:facade
```

Both commands accept `--step=N`, `--step=N1-N2`, `--step=all`, `--no-cleanup`, `--endpoint=…`, `--user=…`, `--password=…`, `--database=…`.

## 🛠️ Generate the documentation

We use [phpDocumentor](https://phpdoc.org/) to generate documentation into the `./docs` folder.

```bash
composer doc
```

## 🤝 Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, coding conventions, the testing workflow and the pull-request process.

## 🧾 License

Licensed under the [Mozilla Public License 2.0 (MPL‑2.0)](https://www.mozilla.org/en-US/MPL/2.0/).

## 👤 About the author

- Author: Marc ALCARAZ (aka eKameleon)
- Email: `marc@ooop.fr`
- Website: `https://www.ooop.fr`

## 🔗 Related packages

| Package | Description |
| --- | --- |
| [oihana/php-auth](https://github.com/BcommeBois/oihana-php-auth) | Casbin RBAC + JWT/OIDC authorization toolkit. |
| [oihana/php-commands](https://github.com/BcommeBois/oihana-php-commands) | Symfony Console kernel and reusable command traits. |
| [oihana/php-core](https://github.com/BcommeBois/oihana-php-core) | Core helpers and utilities shared across the ecosystem. |
| [oihana/php-enums](https://github.com/BcommeBois/oihana-php-enums) | Typed constants and enums — no more magic strings. |
| [oihana/php-exceptions](https://github.com/BcommeBois/oihana-php-exceptions) | Framework exceptions with consistent semantics. |
| [oihana/php-files](https://github.com/BcommeBois/oihana-php-files) | File system helpers (paths, readers, writers). |
| [oihana/php-reflect](https://github.com/BcommeBois/oihana-php-reflect) | Reflection and object hydration utilities. |
| [oihana/php-schema](https://github.com/BcommeBois/oihana-php-schema) | Schema.org constants and vocabulary. |
| [oihana/php-signals](https://github.com/BcommeBois/oihana-php-signals) | Signal/slot dispatcher for decoupled events. |
| [oihana/php-system](https://github.com/BcommeBois/oihana-php-system) | Framework helpers — controllers, models, request handling. |
