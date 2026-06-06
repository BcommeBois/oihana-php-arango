# Testing

![Language](https://img.shields.io/badge/language-English-blue)

The project has **two complementary layers of tests**:

| Layer | Tool | ArangoDB server? | Role |
|---|---|---|---|
| **Unit tests** | PHPUnit | No (mocked transport) | Validate pure logic: AQL builders, filters, facets, helpers, models through a simulated transport. Fast, run on every commit. |
| **Live smoke tests** | Symfony Console (`arango:test:*`) | Yes (ephemeral database) | Validate the whole stack end-to-end against a real `arangod`. |

The contributor workflow is summarised in [CONTRIBUTING.md](../../CONTRIBUTING.md); this page is the detailed reference.

## Unit tests (PHPUnit)

The suite lives in [`tests/`](../../tests) and runs with:

```shell
composer test                                       # = ./vendor/bin/phpunit
./vendor/bin/phpunit --filter FilterFunctionTest    # a single case
```

Configuration: [phpunit.xml](../../phpunit.xml). Key points:

- **Coverage scope**: `./src` only (the `<source>` element).
- **Strict mode**: `failOnWarning`, `failOnRisky`, `failOnSkipped`, `beStrictAboutOutputDuringTests`… A "risky" test (no assertion, produces output, etc.) **fails** the suite on purpose — a test that checks nothing protects nothing.
- **`integration` group excluded** by default: tests that need a real server are tagged `@group integration` and do not run with `composer test`.

### What we test, and how

| Tier | Target | Technique |
|---|---|---|
| 1 | Pure AQL builders (`models/traits/aql/**`, `db/**`, `models/enums/**`) | Input → expected AQL string. No mocks. |
| 2 | Models / edges / controllers | **Mocked** HTTP transport: inject a fake client, then assert the produced request and the response decoding. |
| 3 | Commands & actions | Stubbed DI dependencies. |

> **Characterization testing.** When covering existing code, we write tests that describe what the code **actually does**, branch by branch (`if` / `else` / `match`). That precision work regularly surfaces real bugs (mishandled edge case, unfiltered value…). **Golden rule**: if a surprising behaviour could be relied upon downstream by another library, we **freeze it in a test** and flag it — we never change a public API without explicit validation.

## Code coverage

PHPUnit measures which lines of `./src` the suite executes. You must **enable Xdebug's coverage mode** (or PCOV); otherwise PHPUnit prints `No tests executed!` and a `XDEBUG_MODE=coverage … has to be set` warning. The `composer` scripts below set the environment variable for you:

```shell
composer coverage       # suite + coverage: text in the terminal, Clover + HTML under build/coverage/
composer coverage:md    # regenerate build/coverage/COVERAGE.md (Markdown summary, red zones first)
```

Output goes to `build/coverage/` — **gitignored, never committed**: a numbers snapshot goes stale at the next commit and pollutes diffs. Regenerate on demand. The Clover → Markdown converter lives in [`tools/clover-to-markdown.php`](../../tools/clover-to-markdown.php).

#### Evolution between runs

Each generation timestamps the report (`Generated at YYYY-MM-DD HH:MM:SS`) and appends a snapshot to `build/coverage/history.json` (also gitignored). On the next run the summary compares against the **previous recorded run** and shows a per-metric delta: `▲ +0.14 pts (+12 lines)` / `▼ -0.30 pts (-5 methods)` / `= ±0.00 pts (+0 lines)`.

The timestamp written into the data is authoritative — we deliberately do **not** trust the file's mtime (a `touch`, `checkout` or no-op regeneration would falsify it, and the file vanishes with `build/`). `history.json` is capped at the last 50 runs. Since everything lives under `build/`, this trend is **local only**: for a shared trend (team, CI), publish the report from a CI job rather than committing it.

### Reading the report

- **Lines** = the reference metric (% of executed lines).
- An empty bar = code **never tested** → an undetected potential bug.
- ⚠️ **100% ≠ zero bugs.** A line "walked through" without a solid assertion is *covered* but not really *verified*. So we aim for tests that **assert a precise result**, not ones that merely pass through the code.

Status as of 2026-06-05: **~61% of lines** (≈ 5200 / 8480), 2177 green tests. Largest open gaps: `auth/traits/**` (0%), `controllers/**` (0%), `commands/actions` (~1%).

## Live smoke tests (real ArangoDB)

Two Symfony Console commands validate the ArangoDB stack end-to-end against a real server, **without ever touching production data**.

| Command | Target |
|---|---|
| `./bin/console.php arango:test:clients` | Low-level library `oihana\arango\clients\` (`ArangoClient`, `Database`, `Collection`, `EdgeCollection`, `Cursor`, `AqlQuery`, exceptions, typed indexes). |
| `./bin/console.php arango:test:facade` | High-level façade `oihana\arango\db\ArangoDB` (and its `CollectionManagementTrait`): the 19 public methods that models and controllers actually consume. |

Both commands:

1. create an **ephemeral database** at startup (`arango_clients_test_<random>` or `arangodb_facade_test_<random>`);
2. run every assertion against that database;
3. drop the database on cleanup (`finally` block, even on unexpected exception).

The `--no-cleanup` flag keeps the database around for post-mortem inspection.

## When to use them

- After touching the `clients/` library → `./bin/console.php arango:test:clients`.
- After touching the `db/ArangoDB` façade or `CollectionManagementTrait` → `./bin/console.php arango:test:facade`.
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
./bin/console.php arango:test:clients

# Subset
./bin/console.php arango:test:clients --step=1-3        # steps 1 through 3
./bin/console.php arango:test:clients --step=6          # step 6 only
./bin/console.php arango:test:clients --step=1,3,5      # explicit list

# Inspection
./bin/console.php arango:test:clients --no-cleanup      # keep the ephemeral database
./bin/console.php arango:test:clients --endpoint=tcp://127.0.0.1:8529 --user=root --password=…
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
| 7 | **Exception surface**: an invalid AQL query must surface as `oihana\arango\clients\exceptions\ArangoException`, with the underlying clients/ exception chained through `$previous`. |

### Usage

```shell
# All steps
./bin/console.php arango:test:facade

# Subset
./bin/console.php arango:test:facade --step=1-3
./bin/console.php arango:test:facade --step=6
./bin/console.php arango:test:facade --step=1,3,5

# Inspection
./bin/console.php arango:test:facade --no-cleanup
./bin/console.php arango:test:facade --endpoint=tcp://127.0.0.1:8529 --user=root --password=…
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
- [The HTTP client](clients/README.md) — low-level layer exercised by `arango:test:clients`.
