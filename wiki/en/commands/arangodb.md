# `command:arangodb` — dump / restore / collections

`ArangoCommand` is the maintenance command for an ArangoDB database: **backup** (`dump`), **restore** (`restore`) and **collection inventory** (`collections`). It ships pre-wired by the library under the name `command:arangodb` ([`definitions/commands.php`](../../../definitions/commands.php)) and is used through `php bin/console.php command:arangodb <action> [options]`.

It builds on the [`oihana/php-commands`](../getting-started/dependencies.md#oihanaphp-commands) skeleton (argument/option/output handling via **Symfony Console**): `Kernel` (base class), `CommandArg` (arguments), `CommandOption` (shared options: `--clear`, `--passphrase`, …), `ExitCode`, plus utility traits (`IOTrait`, `EncryptTrait`). The runtime context is therefore a standard Symfony Console application, fed by a **PHP-DI** container.

> **Requirements**: the `arangodump` and `arangorestore` binaries (shipped with ArangoDB) must be on the PHP process `$PATH`. macOS / Homebrew: `brew install arangodb`. The `collections` sub-command and collection validation go through ArangoDB's **HTTP API** (internal client), not the binaries.

---

## Available actions

| Action | Trait | Description |
|---|---|---|
| `dump` | [`ArangoDumpAction`](../../../src/oihana/arango/commands/actions/ArangoDumpAction.php) | Timestamped `arangodump` archive. Whole database or a subset (`--collection` / `--ignore-collection`), AES-encrypted with `--encrypt`. |
| `restore` | [`ArangoRestoreAction`](../../../src/oihana/arango/commands/actions/ArangoRestoreAction.php) | Restore via `arangorestore` from an archive selected by `--last`, `--date`, `--file` or interactively. Whole or subset (`--collection`). |
| `collections` | [`ArangoListCollectionsAction`](../../../src/oihana/arango/commands/actions/ArangoListCollectionsAction.php) | Lists the database collections via the HTTP API. Scopes: user (default), `--system`, `--all`. |
| `listDumps` (`--list`) | [`ArangoListDumpsAction`](../../../src/oihana/arango/commands/actions/ArangoListDumpsAction.php) | Lists the archive files present in the dumps directory. |

---

## Configuration

Two sources feed the command:

| Source | Keys | Read by |
|---|---|---|
| `[arango]` in `configs/config.toml` | `database`, `endpoint`, `user`, `password`, `encrypt`, `passphrase` | [`definitions/config.php`](../../../definitions/config.php) → `arango.config` |
| `[app].dumps` in `configs/config.toml` | Archive directory | [`definitions/config.php`](../../../definitions/config.php) → `app.dumps` |

Dumps directory resolution: **absolute** path → as-is; **relative** → resolved against the library root (`__LIB__`); **missing** → `<library-root>/dumps`.

```toml
[app]
# dumps = "/var/data/arango/dumps"   # absolute — otherwise resolved against the library root

[arango]
database   = "my_db"
endpoint   = "tcp://127.0.0.1:8529"
user       = "root"
password   = "secret"
passphrase = ""                       # default passphrase for --encrypt
encrypt    = false                    # true to encrypt by default
```

---

## DI wiring

The library bootstraps a **PHP-DI** container from [`definitions/`](../../../definitions/) and [`configs/`](../../../configs/) through [`bin/console.php`](../../../bin/console.php):

- [`definitions/config.php`](../../../definitions/config.php) — keys `arango.config` (the `[arango]` section) and `app.dumps`.
- [`definitions/commands.php`](../../../definitions/commands.php) — registers `ArangoCommand::NAME` with its `init` array (description, `CommandParam::ACTIONS`, `ArangoCommandParam::DIRECTORY`, `--encrypt` / `--passphrase`, and the spread of `[arango]`).
- [`definitions/application.php`](../../../definitions/application.php) — adds the command to the Symfony Console `Application`.

### Host-project integration

```php
// api/definitions/commands.php  (host project)
use DI\Container ;
use oihana\arango\commands\ArangoCommand ;
use oihana\arango\commands\enums\ArangoAction ;
use oihana\arango\commands\enums\ArangoCommandParam ;
use oihana\commands\enums\CommandParam ;
use oihana\commands\options\CommandOption ;

return
[
    ArangoCommand::NAME => fn( Container $c ) => new ArangoCommand
    (
        name      : ArangoCommand::NAME ,
        container : $c ,
        init      :
        [
            CommandParam::DESCRIPTION     => 'Manage the project ArangoDB database.' ,
            CommandParam::ACTIONS         =>
            [
                ArangoAction::COLLECTIONS ,
                ArangoAction::DUMP        ,
                ArangoAction::RESTORE     ,
            ] ,
            ArangoCommandParam::DIRECTORY => $c->get( 'paths.dumps' ) , // your own definition
            CommandOption::ENCRYPT        => true ,
            CommandOption::PASS_PHRASE    => $c->get( 'arango.config' )[ 'passphrase' ] ?? null ,

            // Spread of [arango] (database, endpoint, user, password).
            ...$c->get( 'arango.config' ) ,
        ]
    ) ,
] ;
```

```php
// then, on the Symfony Console Application:
$application->addCommand( $container->get( ArangoCommand::NAME ) ) ;
```

> The library performs **no** `{{{projectPath}}}`-style token substitution. If the dumps path must be assembled, the host project's `paths.dumps` definition does it (PHP constants, env vars, `realpath`).

---

## CLI options

| Option | Shortcut | Action(s) | Description |
|---|---|---|---|
| `--collection` | `-c` | dump, restore | Restrict to these collections. **Repeatable** *or* comma-separated (or both). |
| `--ignore-collection` | — | dump | Exclude these collections from the dump (repeatable / comma). Resolved client-side (see below). |
| `--label` | `-L` | dump, restore | Optional label appended to the archive name (e.g. `pre-migration`). |
| `--all` | — | collections | List **all** collections (system + user). |
| `--system` | — | collections | List **only** system collections (`_…`). |
| `--encrypt` | `-e` | dump, restore | AES-encrypt the archive (dump) / decrypt it (restore). |
| `--passphrase` | `-p` | dump, restore | Passphrase for `--encrypt`. Interactive prompt otherwise. |
| `--directory` | `-dir` | all | Override the dump/restore directory. |
| `--list` | `-l` | dump, restore | List the present archives instead of acting. |
| `--last` | `-la` | restore | Pick the most recent archive. |
| `--date` | `-d` | restore | Pick the archive matching an ISO 8601 date. |
| `--file` | `-f` | restore | Explicit path to an archive. |
| `--database` | — | all | Override `[arango].database`. |
| `--endpoint` | — | all | Override `[arango].endpoint`. |
| `--user` | — | all | Override `[arango].user`. |
| `--password` | — | all | Override `[arango].password`. |

---

## `dump` — backup

### Whole database

```bash
php bin/console.php command:arangodb dump
# → <dumps>/2026-06-01T14:30:00-my_db.tar.gz

php bin/console.php command:arangodb dump --encrypt --passphrase 's3cret'
# → <dumps>/2026-06-01T14:30:00-my_db.tar.gz.enc
```

### Subset — `--collection`

Both syntaxes are accepted (and can be mixed):

```bash
php bin/console.php command:arangodb dump --collection=users,products,customers
php bin/console.php command:arangodb dump -c users -c products -c customers
php bin/console.php command:arangodb dump -c users,products -c customers
# → <dumps>/2026-06-01T14:30:00-my_db-partial.tar.gz
```

A targeted dump automatically carries the **`-partial`** marker in its name, to distinguish it from a full backup.

### Label — `--label`

```bash
php bin/console.php command:arangodb dump -c users,products --label pre-migration
# → <dumps>/2026-06-01T14:30:00-my_db-partial-pre-migration.tar.gz

php bin/console.php command:arangodb dump --label nightly        # full + label
# → <dumps>/2026-06-01T14:30:00-my_db-nightly.tar.gz
```

The label only allows `[A-Za-z0-9._-]` (filename safety).

### Exclusion — `--ignore-collection`

`arangodump` has **no** exclusion option. The command resolves it **client-side**: it lists the user collections via the HTTP API, drops the excluded ones, and passes the **complement** as `--collection` to `arangodump`.

```bash
php bin/console.php command:arangodb dump --ignore-collection audit_logs,sessions
#  Ignored collections : audit_logs, sessions
#  → 63 collection(s) will be dumped.
#  → <dumps>/2026-06-01T14:30:00-my_db-partial.tar.gz
```

> Consequence: `--ignore-collection` **requires** the HTTP API to be reachable (the full list is needed to compute the complement). If it is not, the command **fails** with an explicit message.
> `--collection` and `--ignore-collection` are **mutually exclusive**.

### Collection validation (best-effort)

Before a targeted dump, the requested names are checked against the database:

```bash
php bin/console.php command:arangodb dump -c users,prodcts
# [ERROR] Unknown collection(s): prodcts. Available collections: users, products, …
```

If the HTTP API is **unreachable** for a `--collection` (inclusion), validation is **skipped** with a warning and the dump proceeds (`arangodump` may still succeed). For `--ignore-collection`, the API is mandatory (see above).

---

## `restore` — restore

### Archive selection

```bash
php bin/console.php command:arangodb restore                 # interactive menu
php bin/console.php command:arangodb restore --last          # most recent
php bin/console.php command:arangodb restore --date 2026-06-01T14:30:00
php bin/console.php command:arangodb restore --file /var/data/arango/dumps/2026-06-01T14:30:00-my_db.tar.gz
php bin/console.php command:arangodb restore --last --encrypt --passphrase 's3cret'
```

### Targeted restore — `--collection`

```bash
php bin/console.php command:arangodb restore --last --collection users,products
```

### Add / remove semantics of collections

This is the key thing to understand — it holds for both **dump** and **restore**:

- **`--collection` is a filter.**
  - On **dump**, it restricts what gets **written** to the archive.
  - On **restore**, it restricts what gets **read** from the archive — **regardless of its contents**.
- **You can therefore restore a single collection from a full archive.** If the archive holds all 66 collections and you run `restore --collection users`, **only** `users` is restored; the other collections present in the archive are **ignored**, and the **non-targeted collections in the database are left untouched**.
- **`--create-collection` is on by default**: if the targeted collection was **dropped** (not merely emptied), the restore **recreates** it.
- **No duplicates**: `arangorestore` re-inserts documents by `_key`; documents already present with the same key are not duplicated.

#### Demonstration

**Full** dump of 4 collections, then `users` and `products` are broken, then **only** `users` is restored:

| Step | users | products | customers | orders |
|---|:--:|:--:|:--:|:--:|
| start | 3 | 4 | 3 | 2 |
| remove `users/carol` + `products/p4` | **2** | **3** | 3 | 2 |
| `restore --last --collection users` | **3** ✅ | **3** ❌ | 3 | 2 |

```
# Successfully restored document collection 'users'
Processed 1 collection(s) from 1 database(s)
```

→ A **single** collection processed. `users` is restored; `products` stays broken (so **untouched**) even though it is present in the full archive.

### Restoring a *partial* dump by date

`restore --date` rebuilds the filename. For a partial dump you must **re-supply the same targeting** (which triggers `-partial`) **and the same `--label`**:

```bash
# partial dump: 2026-06-01T14:30:00-my_db-partial-pre-migration.tar.gz
php bin/console.php command:arangodb restore --date 2026-06-01T14:30:00 -c users --label pre-migration
```

> Simpler for partial dumps: `--last` or `--file` (which don't need to rebuild the name).

---

## `collections` — inventory

```bash
php bin/console.php command:arangodb collections           # user collections (non-system)
php bin/console.php command:arangodb collections --system  # system only (_apps, _jobs, …)
php bin/console.php command:arangodb collections --all     # everything
```

Read-only, via the HTTP API. Handy to prepare a correct `--collection` / `--ignore-collection`.

---

## Scenario: safe migration

```bash
# 1) Safety net: full dump
php bin/console.php command:arangodb dump

# 2) Targeted dump of the collections NOT touched by the migration, encrypted + labelled
php bin/console.php command:arangodb dump -c users,settings,customers -e -p 's3cret' -L pre-migration
#   → <dumps>/2026-06-01T14:32:10-my_db-partial-pre-migration.tar.gz.enc

# 3) … migration … if something breaks, re-inject ONLY those collections:
php bin/console.php command:arangodb restore --last --collection users,settings,customers
```

The full dump from step 1 stays a universal safety net: you can re-extract **any** collection from it on demand (`restore --file <full> --collection X`) without touching the rest.

---

## Playground database — `scripts/seed-playground.php`

To test `dump` / `restore` / `collections` **without touching** your usual database, the library ships a script that creates and seeds a disposable database.

```bash
# creates/seeds the "dump_playground" database (4 collections: users, products, orders, customers)
php scripts/seed-playground.php

# custom database name
php scripts/seed-playground.php my_test_db
```

Characteristics:

- reads the connection from `[arango]` in `configs/config.toml` (same server as the command), but targets a **separate** database;
- **idempotent**: each run (re)creates and (re)seeds the collections;
- it is **not** an application entry point — it is a development helper ([`scripts/seed-playground.php`](../../../scripts/seed-playground.php)).

Then test against that database via `--database`:

```bash
php bin/console.php command:arangodb collections --database dump_playground
php bin/console.php command:arangodb dump        --database dump_playground -c users,products -L test
php bin/console.php command:arangodb restore     --database dump_playground --last --collection users
```

> Archives from all databases share the same dumps directory. `--last` / `--list` do not filter by database: to target a specific archive, prefer `--file`, or a distinctive `--label`.
