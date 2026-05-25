# Live smoke test commands

![Language](https://img.shields.io/badge/language-English-blue)

Two Symfony Console commands validate the ArangoDB stack end-to-end against a real server, **without ever touching production data**.

| Command | Target |
|---|---|
| `bun arango:test:clients` | Low-level library `oihana\arango\clients\` (`ArangoClient`, `Database`, `Collection`, `EdgeCollection`, `Cursor`, `AqlQuery`, exceptions, typed indexes). |
| `bun arango:test:facade` | High-level façade `oihana\arango\db\ArangoDB` (and its `CollectionManagementTrait`): the 19 public methods that models and controllers actually consume. |

Both commands:

1. create an **ephemeral database** at startup (`arango_clients_test_<random>` or `arangodb_facade_test_<random>`);
2. run every assertion against that database;
3. drop the database on cleanup (`finally` block, even on unexpected exception).

The `--no-cleanup` flag keeps the database around for post-mortem inspection.

## When to use them

- After touching the `clients/` library → `bun arango:test:clients`.
- After touching the `db/ArangoDB` façade or `CollectionManagementTrait` → `bun arango:test:facade`.
- Before a commit that affects the cursor, query options, index grammar or exceptions → both.
- On a new environment (developer machine, CI, staging) to validate the `[arango]` section of `config.toml`.

## `arango:test:clients`

### Coverage — 8 steps, 49 assertions

| Step | Surface |
|---:|---|
| 1 | Server connection: `version()`, `listDatabases()` |
| 2 | Database: `exists()`, empty `collections()` |
| 3 | Collection lifecycle: `create()`, `properties()`, `rename()`, `drop()`, `exists()` at every step |
| 4 | Documents CRUD: `insert/returnNew`, `document`, `documentExists`, `count`, `update/returnNew` (PATCH), `replace` (PUT), `remove/returnOld`, `truncate` |
| 5 | Edge collection: `inEdges()`, `outEdges()`, `edges()` (AQL-backed) |
| 6 | AQL + Cursor: single batch, lazy multi-batch with `batchSize`, `count: true` at the body root, `fullCount: true` nested under `options.{...}` |
| 7 | Indexes: `PersistentIndex` (unique sparse), `TtlIndex`, `dropIndex(fullHandle)`, `index()` with full handle and bare key |
| 8 | Error mapping: `HttpException` on 404 *document-not-found* (`errorNum: 1202`), `ConflictException` on 409 *unique-constraint* (`errorNum: 1210`) |

### Usage

```shell
# All steps
bun arango:test:clients

# Subset
bun arango:test:clients --step=1-3        # steps 1 through 3
bun arango:test:clients --step=6          # step 6 only
bun arango:test:clients --step=1,3,5      # explicit list

# Inspection
bun arango:test:clients --no-cleanup      # keep the ephemeral database
bun arango:test:clients --endpoint=tcp://127.0.0.1:8529 --user=root --password=…
```

Source: [src/oihana/arango/clients/commands/tests/ArangoTestClientsCommand.php](../../src/oihana/arango/clients/commands/tests/ArangoTestClientsCommand.php).

## `arango:test:facade`

### Coverage — 7 steps, 36 assertions

| Step | Surface |
|---:|---|
| 1 | Collection lifecycle (`CollectionManagementTrait`): `collectionCreate / Exists / Rename / Truncate / Drop` |
| 2 | Index ops: `createIndex(IndexOptions)` (legacy DTO), `createIndex(array)` (raw body), `getIndex(collection, fullHandle)`, `getIndexes(name)`, `dropIndex(fullHandle)` |
| 3 | Query (`ArangoDB`): `prepare`, `execute`, `getCursor`, `getDocuments`, **`count($cursor)` via `count: true`** (proves the root-options dispatch path), **multi-`execute()`** (a second `execute()` must replace the previous cursor reference) |
| 4 | Single result helpers: `getFirstResult`, `getObject`, `getResult`, **explicit `INSERT … RETURN NEW`** round-trip |
| 5 | Streaming: `streamDocuments()` (PHP Generator) |
| 6 | **`fullCount` nesting**: `getFoundRows()` + `getExtra()` with `fullCount: true` passed flat through `prepare()`. The critical regression target of Lot 6.1 — if the nesting under `options.{...}` ever breaks, `getFoundRows()` silently reports `0`. |
| 7 | **Exception wrapping**: invalid AQL must raise an `oihana\arango\client\Exception` (legacy) with the new `oihana\arango\clients\exceptions\ArangoException` chained through `$previous`. This is what keeps the ~50 `catch` sites across the project matching during the transition. |

### Usage

```shell
# All steps
bun arango:test:facade

# Subset
bun arango:test:facade --step=1-3
bun arango:test:facade --step=6
bun arango:test:facade --step=1,3,5

# Inspection
bun arango:test:facade --no-cleanup
bun arango:test:facade --endpoint=tcp://127.0.0.1:8529 --user=root --password=…
```

Source: [src/oihana/arango/db/commands/tests/ArangoFacadeTestCommand.php](../../src/oihana/arango/db/commands/tests/ArangoFacadeTestCommand.php).

## Shared options

Both commands share the [`ArangoClientTestTrait`](../../src/oihana/arango/clients/commands/tests/traits/ArangoClientTestTrait.php), which defines the connection options (with CLI override):

| Option | Effect | Default |
|---|---|---|
| `--endpoint <url>` | ArangoDB endpoint | `[arango].endpoint` from `config.toml` |
| `--user <name>` | User | `[arango].user` |
| `--password <pw>` | Password | `[arango].password` |
| `--database <db>` | Fallback database (the commands always create their own ephemeral one anyway) | `[arango].database` |
| `--step <range>` | Subset of steps (`1-3`, `1,3,5`, `all`) | `all` |
| `--no-cleanup` | Keep the ephemeral database after the run | (drop) |

## "Never production" guarantee

The safety contract has three layers:

1. Both commands **compute their own database name** at startup, with a random suffix (`bin2hex(random_bytes(4))`).
2. Every operation (CRUD, indexes, AQL) targets **only this ephemeral database**.
3. The cleanup lives in a `finally` block — it runs even if an assertion fails midway or an unexpected exception bubbles up.

The project's `config.toml` only contributes the **server** and **credentials**; the configured database name is never targeted.

## For contributors

Both commands are **wired through PHP-DI** in the library and ready to run via `bin/console.php`:

- CLI bootstrap: [`bin/console.php`](../../bin/console.php) — Symfony Console entry point.
- DI definitions: [`definitions/commands.php`](../../definitions/commands.php) (registry + factories) + [`definitions/config.php`](../../definitions/config.php) (`arango.config` key) + [`definitions/application.php`](../../definitions/application.php) (`Application::class`).
- Configuration: [`configs/config.example.toml`](../../configs/config.example.toml) (copy to `configs/config.toml` locally before the first run).

Any subsequent test command must follow the same chain: add its factory to `definitions/commands.php`, then register it in `definitions/application.php` via `$application->add(...)`.

## See also

- [Symfony Console commands](commands.md) — `DocumentsCommand` and its business actions (CRUD, harvest, …).
- [Indexes and collection management](indexes.md) — index grammar and the `CollectionManagementTrait`.
- [Legacy ArangoDB client](client.md) — context of the ongoing rewrite.
