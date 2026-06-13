# `command:arangodb` — dump / restore / collections / views / doctor / migrate

`ArangoCommand` is the maintenance command for an ArangoDB database: **backup** (`dump`), **restore** (`restore`), **collection inventory** (`collections`), **ArangoSearch View management** (`views`), **structure health check** (`doctor`) and **versioned data migrations** (`migrate`). It ships pre-wired by the library under the name `command:arangodb` ([`definitions/commands.php`](../../../definitions/commands.php)) and is used through `php bin/console.php command:arangodb <action> [options]`.

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
| `views` | [`ArangoViewsAction`](../../../src/oihana/arango/commands/actions/ArangoViewsAction.php) | ArangoSearch View management via the HTTP API: list (default), `--diff` / `--sync` against the models' `AQL::VIEW` declarations, targeted or interactive `--drop`. |
| `doctor` | [`ArangoDoctorAction`](../../../src/oihana/arango/commands/actions/ArangoDoctorAction.php) | Declarations ↔ server health check of the whole structure of the configured models (collections, indexes, Views) + orphans. Report by default, `--apply` repairs, interactive `--prune`. |
| `migrate` | [`ArangoMigrateAction`](../../../src/oihana/arango/commands/actions/ArangoMigrateAction.php) | Versioned **data** migrations: PHP `up()`/`down()` classes run once per database, tracked in the database. `--status`, `--dry-run`, apply (with confirmation), `--down[=n]`, `--forget`, `--create`. |

---

## Configuration

Two sources feed the command:

| Source | Keys | Read by |
|---|---|---|
| `[arango]` in `configs/config.toml` | `database`, `endpoint`, `user`, `password`, `encrypt`, `passphrase` | [`definitions/config.php`](../../../definitions/config.php) → `arango.config` |
| `[arango.dump]` / `[arango.restore]` | Any `arangodump` / `arangorestore` option (see below) | `ArangoOptionsTrait` via the `dump` / `restore` init keys |
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

### Configuring option defaults

Beyond the connection, the `dump` and `restore` actions accept **every**
`arangodump` / `arangorestore` option. The most common ones have a dedicated
CLI flag (see [CLI options](#cli-options)); **all** of them can be set as
persistent defaults in two optional sections of `config.toml`:

| Section | Applies to | Example keys |
|---|---|---|
| `[arango.dump]` | `dump` | `threads`, `overwrite`, `includeSystemCollections`, `dumpViews`, `compressOutput`, `splitFiles` |
| `[arango.restore]` | `restore` | `threads`, `includeSystemCollections`, `view`, `forceSameDatabase`, `numberOfShards` |

The keys are the option **property names** of `ArangoDumpOptions` /
`ArangoRestoreOptions` (camelCase). Unknown keys are silently ignored.

```toml
[arango.dump]
threads                  = 4
overwrite                = true
includeSystemCollections = false      # default

[arango.restore]
threads   = 4
protected = ["users", "sessions"]    # guard-rail, not an option — see "Restore guard-rails"
```

> `protected` is the one key in `[arango.restore]` that is **not** an
> `arangorestore` option: it is a safety policy (see
> [Restore guard-rails](#restore-guard-rails)), stripped before the binary runs.

**Precedence** — each layer overrides the previous one:

| Layer | Source |
|---|---|
| 1. binary default | `arangodump` / `arangorestore` built-in |
| 2. config default | `[arango.dump]` / `[arango.restore]` |
| 3. CLI flag | `--threads`, `--include-system`, … |

A one-off run therefore refines a configured default without editing `config.toml`.

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
                ArangoAction::VIEWS       ,
            ] ,
            ArangoCommandParam::DIRECTORY => $c->get( 'paths.dumps' ) , // your own definition

            // Models whose AQL::VIEW declarations the `views` action inspects
            // (container ids, like Arango::MODEL on the controller side).
            ArangoCommandParam::MODELS    => [ Models::PLACES , Models::PRODUCTS ] ,
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
| `--complete` | — | dump | Complete backup: every user collection **plus** `_analyzers` and `_graphs` — see [Complete backup](#complete-backup). |
| `--label` | `-L` | dump, restore | Optional label appended to the archive name (e.g. `pre-migration`). |
| `--all` | — | collections | List **all** collections (system + user). |
| `--system` | — | collections | List **only** system collections (`_…`). |
| `--encrypt` | `-e` | dump, restore | AES-encrypt the archive (dump) / decrypt it (restore). |
| `--passphrase` | `-p` | dump, restore | Passphrase for `--encrypt`. Interactive prompt otherwise. |
| `--directory` | `-dir` | all | Override the dump/restore directory. |
| `--list` | `-l` | dump, restore | List the present archives instead of acting. |
| `--include-system` | — | dump, restore | Include the system collections (`_analyzers`, `_graphs`, …). |
| `--no-views` | — | dump | Skip the ArangoSearch View definitions (dumped by default). |
| `--all-databases` | — | dump, restore | Dump / restore **every** database instead of a single one. |
| `--overwrite` | — | dump | Overwrite the output directory if it already exists. |
| `--threads` | — | dump, restore | Number of parallel threads. |
| `--view` | — | restore | Restrict the restore to these Views (repeatable / comma-separated). |
| `--profile` | — | dump, restore | Named profile (`[arango.profiles.<name>]`) or a path to a `.toml` profile file — see [Profiles](#profiles). |
| `--dry-run` | — | dump, restore, migrate | Report the resolved plan (and the pending migrations) without running anything. |
| `--apply` | — | doctor | Repair: create what is missing (collections, indexes, Views), resynchronize the Views. |
| `--force` | — | doctor, restore | doctor: with `--apply`, allow the drop + recreate of drifted indexes. restore: overwrite **protected** collections (see [Restore guard-rails](#restore-guard-rails)). |
| `--prune` | — | doctor | Interactive selection of the orphans (collections, Views) to remove. |
| `--create` | — | migrate | Generate an empty migration shell with this description. |
| `--status` | — | migrate | Table of applied / pending migrations for this database. |
| `--yes` | `-y` | migrate, restore | Skip the confirmation prompt (apply migrations / restore). |
| `--down` | — | migrate | Roll back the last N applied migrations, default 1 (LIFO). |
| `--forget` | — | migrate | Rescue: drop a tracking row **without** running its `down()`. |
| `--diff` | — | views | Compare the `AQL::VIEW` declarations of the configured models with the server state (read-only). |
| `--sync[=a,b]` | — | views | Create the missing Views and resynchronize the drifted ones — all, or the given names (comma-separated). |
| `--drop[=a,b]` | — | views | Drop the named Views (comma-separated), or interactive selection without a value. |
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

### Complete backup — `--complete`

A default dump backs up the user collections, their indexes and the
ArangoSearch **View definitions** — but **not** the custom **analyzers**
(`_analyzers`) or the **named graphs** (`_graphs`), which live in system
collections. On a restore into a *fresh* server this gap can break a View that
references a custom analyzer.

`--complete` closes the gap **surgically**: it backs up every user collection
**plus** `_analyzers` and `_graphs` — and *only* those two system collections
(never `_users`, `_jobs`, `_queues`, …).

```bash
php bin/console.php command:arangodb dump --complete
```

It can also be the default via config:

```toml
[arango.dump]
complete = true
```

What a backup covers:

| Layer | Default dump | `--complete` |
|---|---|---|
| User collections + data | ✅ | ✅ |
| Indexes | ✅ | ✅ |
| ArangoSearch View definitions | ✅ | ✅ |
| Custom analyzers (`_analyzers`) | ❌ | ✅ |
| Named graphs (`_graphs`) | ❌ | ✅ |
| Users / permissions (`_users`) | ❌ | ❌ |
| Foxx services (`_apps`) | ❌ | ❌ |

`--complete` requires the HTTP API (it enumerates the collections) and is
mutually exclusive with `--collection` / `--ignore-collection` / `--profile`
(it backs up the whole database, not a subset). To restore it, re-include the
system collections:

```bash
php bin/console.php command:arangodb restore --last --include-system
```

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

### Restore guard-rails

`restore` is the **only destructive action**: it writes into a real database.
Four guard-rails frame it.

**1. Protected collections (`[arango.restore] protected`).** A deployment-level
list — the restore **refuses** to overwrite these collections **unless `--force`**.
Put your authentication collections here so a full restore launched by mistake can
never clobber them.

```toml
# config.toml
[arango.restore]
protected = ["users", "sessions", "permissions"]
```

```bash
php bin/console.php command:arangodb restore --last
# [ERROR] Refusing to overwrite protected collection(s): users — rerun with --force to override.

php bin/console.php command:arangodb restore --last --force --yes
# [WARNING] --force: this WILL overwrite protected collection(s): users
```

> `protected` is a **policy**, never an `arangorestore` option: the key is stripped
> from the options passed to the binary. `--force` lifts it, per run.

**2. Confirmation (`--yes`).** Before writing, the restore asks for confirmation
(like `migrate`). `--yes` skips the prompt (CI, `bun pull`). A **non-interactive run
without `--yes`** stops, by safety — never a silent overwrite.

```bash
php bin/console.php command:arangodb restore --last          # asks "Restore into 'app' ? [y/N]"
php bin/console.php command:arangodb restore --last --yes    # no prompt
```

**3. Non-local target warning.** When the target endpoint is not local
(`localhost` / `127.0.0.1` / `::1`), a warning is printed — to avoid restoring onto
staging/prod while believing you target local. It is a **warning**, not a block (a
remote restore is sometimes intentional).

```
[WARNING] The target endpoint is NOT local: tcp://staging.internal:8529 — make sure
you are not overwriting a staging/production database.
```

**4. Selection validation.** A requested collection (`--collection` or a profile)
**absent from the archive** triggers a warning (typo / wrong archive). Non-blocking.

> **The source archive is consumed only on success.** A restore refused by a
> guard-rail (protected, declined confirmation) leaves the backup **untouched**.

All these guard-rails are previewable with `--dry-run` (target, list, known protected
conflicts, non-local warning), writing nothing.

---

## Profiles

A **profile** is a reusable, named selection — *what* to extract, and optionally
*from where*. Instead of retyping a long `--collection a --collection b …` each
time, you name it once and pass `--profile <name>`.

### The staging → local recipe

The motivating case: pull a subset of **staging** into your **local** database to
test against real data — **without ever overwriting your local authentication
collections**.

```toml
# config.toml (project) — or a standalone file (see below)
[arango.profiles.test-local]
collections = ["thesaurus", "products", "clients", "sales-reps"]
edges       = ["product_thesaurus", "client_sales-rep"]
exclude     = ["_users", "sessions"]
```

```bash
# Pull the subset from staging:
php bin/console.php command:arangodb dump --profile test-local
# Restore it into the local database:
php bin/console.php command:arangodb restore --profile test-local --last
```

Because the dump only **contains** the selected collections, the local restore
**cannot** overwrite your local `_users` — the positive list is the protection.

### Profile keys

| Key | Meaning |
|---|---|
| `collections` / `edges` | The positive selection (merged into one list). |
| `exclude` | Names removed from the resolved set (set subtraction). |
| `endpoint` / `database` / `user` / `password` | An optional **source** connection — used by `dump` only (see safety below). |

Selection resolves to `(collections + edges) − exclude`. An **exclude-only**
profile (no positive list) means *"everything minus exclude"* — the universe is
the server collections for `dump`, and the archive collections for `restore`.

### Named or external file

`--profile` accepts either form:

- `--profile test-local` → the `[arango.profiles.test-local]` section of `config.toml`.
- `--profile ./profiles/test-local.toml` (or an absolute path) → a standalone
  file whose root keys *are* the profile. Portable — keep it on a server or share
  it across machines.

```toml
# /srv/arango/profiles/staging-extract.toml — self-contained
collections = ["thesaurus", "products"]
exclude     = ["_users"]
endpoint    = "tcp://staging.internal:8529"
database    = "app_staging"
user        = "readonly"
password    = "•••"
```

> A profile file may carry credentials → keep it **out of version control** with
> restricted permissions, like `config.toml`.

### Safety

- **A profile's connection is the source.** `dump` uses it (pull *from* there);
  `restore` **ignores** it and always writes to the **local** target
  (`[arango]` / CLI). A profile can never push its data back onto the server it
  came from.
- **`--profile` is exclusive** with `--collection` / `--ignore-collection` — pick
  one selection mode.
- **Precedence** stays `binary default → [arango.dump]/[arango.restore] → profile → CLI`.

### Dry run

`--dry-run` reports the resolved plan — connection, archive, and the exact
collection list — and runs **nothing**:

```bash
php bin/console.php command:arangodb restore --profile test-local --last --dry-run
# Target  : app @ tcp://127.0.0.1:8529 (local)
# Collections : thesaurus, products, clients, sales-reps
# [OK] Dry run — nothing was restored.
```

---

## `collections` — inventory

```bash
php bin/console.php command:arangodb collections           # user collections (non-system)
php bin/console.php command:arangodb collections --system  # system only (_apps, _jobs, …)
php bin/console.php command:arangodb collections --all     # everything
```

Read-only, via the HTTP API. Handy to prepare a correct `--collection` / `--ignore-collection`.

---

## `views` — ArangoSearch View management

```bash
php bin/console.php command:arangodb views                    # list the Views (name, type, linked collections)
php bin/console.php command:arangodb views --diff             # compare the model declarations with the server
php bin/console.php command:arangodb views --sync             # create the missing ones, resynchronize the drifted ones
php bin/console.php command:arangodb views --sync=placesView  # targeted sync (commas accepted)
php bin/console.php command:arangodb views --drop=a,b         # targeted drop
php bin/console.php command:arangodb views --drop             # interactive selection (multi-choice)
```

Composer shortcut: `composer arango:views` (flags after `--`, e.g. `composer arango:views -- --diff`).

### Why

The model provisioning ([View search](../db/search-views.md)) is *create-if-missing*: changing the `AQL::VIEW` block does **not** update an existing View — an added field is silently not indexed, a changed Analyzer barely matches anything anymore. `views --diff` detects that drift, `views --sync` repairs it through `updateProperties()`: the View stays queryable while the inverted index rebuilds in the background, and neither the View options (`commitIntervalMsec`, …) nor the links of other collections are touched.

### Wiring `--diff` / `--sync` in a host project (notice)

`--list` and `--drop` work with no configuration at all (the `[arango]` connection / CLI options). `--diff` / `--sync`, however, read the `AQL::VIEW` declarations of **your models** — three steps in the host project:

1. **Enable the action** — add `ArangoAction::VIEWS` to the `CommandParam::ACTIONS` array of the command's DI definition (see [DI wiring](#di-wiring) above).
2. **List the models to inspect** — `ArangoCommandParam::MODELS => [ Models::PLACES , Models::PRODUCTS ]`: the **container ids** of the `Documents` definitions, exactly like `Arango::MODEL` on the controller side.
3. **Declare the View on the model** — every inspected model carries its `AQL::VIEW` block (`Search::NAME` / `Search::ANALYZER` / `Search::FIELDS`, see [View search](../db/search-views.md)).

A listed model without an `AQL::VIEW` block is simply reported as "no View declared" and skipped. Each model is queried on **its own** database (`AQL::DATABASE`).

```bash
$ php bin/console.php command:arangodb views --diff

 Diff the declared views
 -----------------------

 ~ placesView (models.places) — drifted
     · places.fields.description : not indexed on the server
 ✓ productsView (models.products) — in sync

 Orphan views (declared by no configured model) : legacyView
 Use `views --drop=name` to remove them explicitly.

$ php bin/console.php command:arangodb views --sync
 ✓ placesView (models.places) — resynchronized
 ✓ productsView (models.products) — in sync
```

### Report statuses

| Status | Meaning | `--sync` effect |
|---|---|---|
| `inSync` | the server View matches the declaration | none |
| `missing` | declared but absent from the server | creation |
| `drifted` | field not indexed, different Analyzer, removed field still indexed, … | `updateProperties()` |
| `invalid` | malformed declaration, unknown Analyzer or collection, type conflict | never touched |
| `unreachable` | server unreachable | never touched |

Worth knowing:

- `--diff` is **side-effect free**: the command disables the lazy provisioning of the inspected models through the container `lazy` entry (`LazyTrait`) before resolving them — nothing gets created during a report.
- **Orphan views** (on the server, declared by no configured model) are listed as a report footnote — report only, removal always goes through an explicit `--drop`.
- The exit code fails as soon as a model is `unreachable` — usable as-is in a deployment script (`bun pull`, CI, …).
- The same primitives are available straight from PHP: `$model->viewDiff()` / `$model->viewSync()` (returning a `DiffReport`), and on the façade `$db->viewDiff( $name , $links )` / `$db->viewSync( $name , $links )`.

---

## `doctor` — structure health check

```bash
php bin/console.php command:arangodb doctor                  # report : collections, indexes, Views + orphans
php bin/console.php command:arangodb doctor --apply          # create what is missing, resync the Views
php bin/console.php command:arangodb doctor --apply --force  # + drop & recreate the drifted indexes
php bin/console.php command:arangodb doctor --prune          # interactive removal of the orphans
```

Composer shortcut: `composer arango:doctor` (flags after `--`, e.g. `composer arango:doctor -- --apply`).

### Why

The lazy model provisioning is *create-if-missing* — and for indexes it only ever runs **when the collection itself is created**: an index added to the `AQL::INDEXES` block of a model whose collection already exists is **never created**, on any environment, with no error whatsoever (queries silently fall back to full scans). `doctor` is the conformity command: it compares everything the models declare (`AQL::COLLECTION` + type, `AQL::INDEXES`, `AQL::VIEW`) with the actual server state, and `--apply` repairs.

```bash
$ php bin/console.php command:arangodb doctor

 Diagnose the declared structure
 -------------------------------

 models.places
   ✓ places [collection] — in sync
   ~ places [indexes] — drifted
       · byName : missing on the server
   ✓ placesView [view] — in sync

 Orphans (declared by no configured model) :
     · collection : old_imports
 Use `doctor --prune` (interactive) to remove them explicitly.

 1 model(s) — 2 in sync, 0 missing, 1 drifted, 0 invalid, 0 unreachable ; 1 orphan(s).
```

### What the report checks

| Object | Checks | `--apply` repair |
|---|---|---|
| Collection | existence, type (2 = document, 3 = edge) | creation (with its indexes); a drifted type is **never** repaired (recreating means losing the documents → that is a migration) |
| Indexes | presence of every declared index, field-by-field definition (**ordered** `fields`, `unique`, `sparse`, …), undeclared server indexes | missing ones created; drifted ones are **announced** and only rebuilt (drop + recreate) with `--force` |
| View | the full `views --diff` report (fields, analyzers, declaration coherence) | `viewSync()` (`updateProperties()`) |
| Orphans | collections (non-system) and Views on the server declared by no model | never automatic — interactive `--prune` only |

Why `--force` is separate: an index is **immutable** — repairing it means dropping then recreating it, with a window where queries lose it, and a `unique` index may fail to recreate if duplicates appeared in the meantime. Not something a routine `--apply` should do on its own.

### Health-check exit code

The report mode fails (exit ≠ 0) as soon as something is `missing`, `drifted`, `invalid` or `unreachable` — a green `doctor` guarantees the structure matches the declarations (CI-friendly). **Orphans never fail** (they are a warning). In `--apply` mode the command only fails when something could not be repaired.

### Wiring and PHP usage

The wiring is **the same as `views`** ([notice](#wiring---diff----sync-in-a-host-project-notice)): `ArangoAction::DOCTOR` in `ACTIONS`, and the same `ArangoCommandParam::MODELS` key feeds both actions. Typical deployment workflow: `git pull` → `composer install` → `arangodb doctor --apply` → the structure is conform.

The same operations are available straight on the models — `$model->diagnose()` (read-only, a list of `DiffReport`: collection, indexes, View) and `$model->repair( force: bool )` — and on the façade: `$db->collectionDiff()`, `$db->indexesDiff()` / `indexesSync()`, `$db->viewDiff()` / `viewSync()`.

---

## `migrate` — versioned data migrations

```bash
php bin/console.php command:arangodb migrate --create "multilingual description"  # generate a shell
php bin/console.php command:arangodb migrate --status      # applied / pending, for THIS database
php bin/console.php command:arangodb migrate --dry-run     # list the pending ones, without running them
php bin/console.php command:arangodb migrate              # apply the pending ones (with confirmation)
php bin/console.php command:arangodb migrate --yes        # apply without confirmation (bun pull / CI)
php bin/console.php command:arangodb migrate --down       # roll back the last applied one
php bin/console.php command:arangodb migrate --down=3     # roll back the last 3
php bin/console.php command:arangodb migrate --forget=20260612090000_AddKind   # rescue
```

Composer shortcut: `composer arango:migrate -- --status`.

### `doctor` or `migrate`? The "from where you sit" rule

These are **two separate worlds that never cross**:

| You change… | Tool | Migration? |
|---|---|---|
| a DI declaration — collection, index, Analyzer, `AQL::VIEW` block (the **structure**) | `doctor --apply` | **no**, ever |
| the **content** of documents already in the database — transform, normalize, dedupe, backfill | `migrate` | **yes**, a small migration |

`doctor` **never** asks for a migration. You write a migration only the day you must rework existing data — in practice a handful per year. Example: `description` goes from a plain string to `{ fr, en }`. Adding the multilingual field to the DI declaration → `doctor`. Transforming the old documents (`"text"` → `{ fr: "text", en: null }`) → a migration, because a data transformation is an **algorithm**, not a state expressible as configuration.

### Anatomy of a migration

`migrate --create "…"` generates an **empty shell** — the class, the timestamp, empty `up()`/`down()` — and prints its path. The tool guesses nothing: you write the intent in `up()`.

```php
// api/src/fr/bouney/migrations/Version20260612090000_DescriptionMultilingue.php
namespace fr\bouney\migrations ;

use oihana\arango\migrations\Migration ;

class Version20260612090000_DescriptionMultilingue extends Migration
{
    public function description() : string { return 'description string → { fr, en }' ; }

    public function up() : void
    {
        // free AQL — the escape hatch for any transformation
        $this->query( 'FOR doc IN places FILTER TYPENAME(doc.description) == "string"
                       UPDATE doc WITH { description: { fr: doc.description, en: null } } IN places' ) ;
    }

    public function down() : void
    {
        $this->query( 'FOR doc IN places FILTER TYPENAME(doc.description) == "object"
                       UPDATE doc WITH { description: doc.description.fr } IN places' ) ;
    }
}
```

The `Migration` class receives the `ArangoDB` façade (`$this->db`) — so free AQL via `$this->query()`, the collection CRUD and even the doctor primitives — plus a **toolbox** of common operations to avoid hand-writing AQL:

```php
public function up() : void
{
    $this->renameField( 'contacts' , 'tel' , 'phone' ) ;   // "tel" → "phone" on every document
    $this->dropField( 'places' , 'legacy' ) ;              // remove an obsolete attribute
    $this->setDefault( 'orders' , 'status' , 'pending' ) ; // backfill where the field is missing / null
}
```

### The golden rule about editing

A migration has two lives:

- **not yet applied** (on your machine, locally) → you edit it **freely**; you can test it in a loop (`migrate`, `migrate --down`, re-edit, `migrate`);
- **already applied** (committed, gone to staging/prod) → **never edit it again**: it is marked "done" in each database's tracking, so a re-edit would go nowhere. A fix is a **new migration**.

This is what guarantees prod = staging = local.

### Where the files live, and the wiring

The `Version*.php` files live in **your host project** (e.g. `api/src/fr/bouney/migrations/`, under the `fr\bouney\migrations` namespace). The library only provides the `Migration` base class and the engine. Three command init keys:

```php
// api/definitions/@commands/arangodb.php
ArangoCommandParam::MIGRATIONS_PATH      => $c->get( Paths::MIGRATIONS ) ,   // the Version*.php directory
ArangoCommandParam::MIGRATIONS_NAMESPACE => 'fr\\bouney\\migrations' ,        // their PHP namespace
ArangoCommandParam::MIGRATIONS_COLLECTION => 'migrations' ,                   // the tracking collection (default)
```

plus `ArangoAction::MIGRATE` in `CommandParam::ACTIONS`.

### The database tracking

Every applied migration writes **one row** in the tracking collection (`migrations`, one per database — because staging, prod and your machine are at different levels that the code alone cannot know). The document is a schema.org value-object (`MigrationAction` ⊂ `UpdateAction`): `_key` = version, `actionStatus` (`active` → `completed` | `failed`), `startTime`/`endTime`, `agent` (`user@host`), `error`, plus the **`gitCommit`** hash of the current commit — the link between the database and the source tree. A nominal run inserts the row as `active`, runs `up()`, flips it to `completed`; if `up()` throws, the row flips to `failed` and the run **stops immediately** (never a half-migrated database).

> The tracking is **shared with `doctor --apply`**: each structure object actually created/repaired is journaled as a `CreateAction` (told apart from migrations by its `additionalType`). One collection, one vocabulary, two event families — `migrate` ignores the `doctor` rows.

### Safety

- **Confirmation**: an interactive `migrate` lists the pending migrations then asks `[y/N]`. `--yes` skips it (scripts); a **non-interactive run without `--yes` stops** (never a silent data migration).
- **LIFO rollback**: `--down` rewinds **from the end** (the stack), never a migration in the middle. A migration with no `down()` (the no-op default) is un-tracked with no effect on the data.
- **`--forget`** is a **rescue** operation: it drops a tracking row **without** running `down()` — to repair a drifted tracking (a migration undone by hand). Dangerous: the migration becomes pending again.

### Typical deployment

```bash
git pull                                  # the code + the new migrations
composer install
arango doctor --apply                     # 1) the structure conforms (declarative)
arango migrate --yes                      # 2) the data transforms (versioned)
```

The order is always **structure then data**: `doctor` first, `migrate` next.

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
