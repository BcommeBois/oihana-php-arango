# Symfony Console commands

The [`src/oihana/arango/commands/`](../../src/oihana/arango/commands/) folder exposes the [`Documents`](models.md) business layer on the **command line** side. Same CRUD operations as the HTTP controllers, accessible via `php bin/console.php <name>` or a `bun` alias on the project side.

Two pivot classes:

| Class | Role | Exposed actions |
|---|---|---|
| `ArangoCommand` | Database maintenance: dump, restore, list dumps. | `dump`, `restore`, `dump --list` |
| `DocumentsCommand` | Full CRUD on a collection. | `get`, `list`, `count`, `exist`, `last`, `insert`, `update`, `replace`, `upsert`, `delete`, `truncate`, `harvest` |

Both inherit from the [`oihana/php-commands`](getting-started/dependencies.md#oihanaphp-commands) skeleton, which provides argument handling, options, output formats (JSON, table, raw) and return codes.

## `ArangoCommand`

`ArangoCommand` is shipped pre-wired by the library and registered under the name `command:arangodb` by [`definitions/commands.php`](../../definitions/commands.php). It is immediately usable via `php bin/console.php command:arangodb …` once `configs/config.toml` has been created.

> 📖 **Detailed documentation**: [`commands/arangodb.md`](commands/arangodb.md) — targeted dump/restore (`--collection` / `--ignore-collection` / `--label`), the `collections` sub-command, collection add/remove semantics, and the `scripts/seed-playground.php` playground database.

### Available actions

| Action | Trait | Typical output |
|---|---|---|
| `dump` | [`ArangoDumpAction`](../../src/oihana/arango/commands/actions/ArangoDumpAction.php) | Timestamped `arangodump` archive (`YYYY-MM-DDTHH:MM:SS-<db>.tar.gz`), AES-encrypted when `--encrypt`. |
| `restore` | [`ArangoRestoreAction`](../../src/oihana/arango/commands/actions/ArangoRestoreAction.php) | Reinjection via `arangorestore` from an archive selected by file, date, or interactively. |
| `listDumps` (`--list`) | [`ArangoListDumpsAction`](../../src/oihana/arango/commands/actions/ArangoListDumpsAction.php) | Lists dumps available in the dumps directory. |

> The `arangodump` / `arangorestore` binaries (shipped with ArangoDB) must be on the PHP process `$PATH`. On macOS via Homebrew: `brew install arangodb`.

### Configuration

Two sources feed the command:

| Source | Keys consumed | Read by |
|---|---|---|
| `[arango]` of `configs/config.toml` | `database`, `endpoint`, `user`, `password`, `encrypt`, `passphrase` | [`definitions/config.php`](../../definitions/config.php) → `arango.config` |
| `[app].dumps` of `configs/config.toml` | Dumps directory | [`definitions/config.php`](../../definitions/config.php) → `app.dumps` |

Dumps directory resolution:

- **absolute** path (`/var/data/arango/dumps`) → used as-is;
- **relative** path (`dumps`, `var/dumps`) → resolved against the library root (`__LIB__`);
- **missing or empty** key → defaults to `<library-root>/dumps`.

> The [`dumps/`](../../dumps/) directory ships tracked with an internal `.gitignore` that excludes archives — the default works out of the box (`--list` returns a clean empty message, and the first `dump` does not need to create the directory).

Minimal `configs/config.toml`:

```toml
[app]
# dumps = "/var/data/arango/dumps"   # absolute — otherwise resolved against the library root

[arango]
database   = "my_db"
endpoint   = "tcp://127.0.0.1:8529"
user       = "root"
password   = "secret"
passphrase = ""                       # default passphrase for --encrypt
encrypt    = false                    # flip to true to encrypt by default
```

### CLI usage (lib standalone)

```bash
# Dump (defaults: [arango] section, [app].dumps directory)
php bin/console.php command:arangodb dump

# List the present dumps
php bin/console.php command:arangodb dump --list

# Encrypted dump (interactive passphrase prompt if not provided)
php bin/console.php command:arangodb dump --encrypt
php bin/console.php command:arangodb dump --encrypt --passphrase mysecret

# Override the database or endpoint
php bin/console.php command:arangodb dump --database other_db --endpoint tcp://10.0.0.5:8529

# Override the output directory
php bin/console.php command:arangodb dump --directory /tmp/snapshots

# Restore — interactive selection across present archives
php bin/console.php command:arangodb restore

# Restore — latest archive
php bin/console.php command:arangodb restore --last

# Restore — by date
php bin/console.php command:arangodb restore --date 2026-05-17T18:14:22

# Restore — explicit file
php bin/console.php command:arangodb restore --file /var/data/arango/dumps/2026-05-17T18:14:22-my_db.tar.gz.enc

# Restore an encrypted archive
php bin/console.php command:arangodb restore --last --encrypt --passphrase mysecret
```

### DI definition (host-project integration)

The lib exposes the command under the name `ArangoCommand::NAME` (= `command:arangodb`). When integrating in **your own** application, ignore the lib's `[app].dumps` key and wire your own `directory`:

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
            CommandParam::DESCRIPTION    => 'Manage the project ArangoDB database.' ,
            CommandParam::ACTIONS        =>
            [
                ArangoAction::DUMP ,
                ArangoAction::RESTORE ,
            ] ,
            ArangoCommandParam::DIRECTORY => $c->get( 'paths.dumps' ) , // your own definition
            CommandOption::ENCRYPT        => true ,
            CommandOption::PASS_PHRASE    => $c->get( 'arango.config' )[ 'passphrase' ] ?? null ,

            // Spread the [arango] section (database, endpoint, user, password).
            ...$c->get( 'arango.config' ) ,
        ]
    ) ,
] ;
```

Then add the command to your Symfony Console `Application`:

```php
$application->addCommand( $container->get( ArangoCommand::NAME ) ) ;
```

> **Note** — the open-source lib performs **no** `{{{projectPath}}}`-style token substitution in the TOML. If your project needs such injection, the host project's own `paths.dumps` definition is responsible for assembling the final path (from PHP constants, env vars, or `realpath`) — not the library.

### CLI options

| Option | Short | Description |
|---|---|---|
| `--directory` | `-dir` | Override the dump/restore directory. |
| `--encrypt` | `-e` | Enable AES encryption of the archive (dump) or decrypt it (restore). |
| `--passphrase` | `-p` | Passphrase for `--encrypt` / encrypted-archive restore. Interactive prompt otherwise. |
| `--list` | `-l` | On `dump` or `restore`: list present archives instead of running the action. |
| `--last` | `-la` | On `restore`: automatically pick the most recent archive. |
| `--date` | `-d` | On `restore`: pick the archive matching an ISO 8601 date. |
| `--file` | `-f` | On `restore`: explicit path to an archive (short-circuits selection). |
| `--database` | — | Override `[arango].database`. |
| `--endpoint` | — | Override `[arango].endpoint`. |
| `--user` | — | Override `[arango].user`. |
| `--password` | — | Override `[arango].password`. |

## `DocumentsCommand`

### Available actions

`DocumentsCommand` exposes one command per collection. Each command accepts an **action** (first positional argument) that determines the operation to perform.

| Action | Class | Model equivalent | Usage example |
|---|---|---|---|
| `get` | `DocumentsCommandGet` | `get()` | Retrieves a document by key. |
| `list` | `DocumentsCommandList` | `list()` | Paginated listing. |
| `count` | `DocumentsCommandCount` | `count()` | Counts documents matching. |
| `exist` | `DocumentsCommandExist` | `exist()` | Existence test (return code 0/1). |
| `last` | `DocumentsCommandLast` | `last()` | Last document by `SORT_DEFAULT`. |
| `insert` | `DocumentsCommandInsert` | `insert()` | New document insertion from JSON. |
| `update` | `DocumentsCommandUpdate` | `update()` | Partial update. |
| `replace` | `DocumentsCommandReplace` | `replace()` | Full replacement. |
| `upsert` | `DocumentsCommandUpsert` | `upsert()` | Insert or update. |
| `delete` | `DocumentsCommandDelete` | `delete()` | Removal (with *edges* cascade). |
| `truncate` | `DocumentsCommandTruncate` | `truncate()` | Empties the collection. |
| `harvest` | `DocumentsCommandHarvest` | Custom | Periodic import cycle from an external source. |

### DI definition

```php
use DI\Container ;
use oihana\arango\commands\DocumentsCommand ;

return
[
    Commands::USERS => fn( Container $c ) => new DocumentsCommand( $c ,
    [
        DocumentsCommandParam::NAME  => 'users'         ,
        DocumentsCommandParam::MODEL => Models::USERS    ,
    ]) ,
] ;
```

One definition = one command = one collection. The `MODEL` is the DI identifier of the underlying [`Documents`](models.md) model. The command automatically inherits all configuration (filters, *skins*, *edges*) from the model.

### CLI usage

```bash
# Retrieve a document
php api/bin/console.php users get --key=abc123

# List first 20
php api/bin/console.php users list --limit=20

# Count
php api/bin/console.php users count

# Insertion from JSON
php api/bin/console.php users insert --data='{"_key":"john","email":"john@example.com","active":true}'

# Partial update
php api/bin/console.php users update --key=john --data='{"active":false}'

# Removal
php api/bin/console.php users delete --key=john

# Truncate (with interactive confirmation)
php api/bin/console.php users truncate

# Bypass confirmation
php api/bin/console.php users truncate --force
```

### Global options

| Option | Description |
|---|---|
| `--verbose`, `-v` | Increased verbosity. `-vv` and `-vvv` for more details. |
| `--quiet`, `-q` | No output beyond errors. |
| `--dry-run` | Displays the AQL that would be executed, without doing it. |
| `--force` | Bypass interactive confirmations (useful for `truncate`, bulk `delete`). |
| `--format=json\|table\|raw` | Output format. `json` by default. |
| `--filter=<json>` | Filter conditions (same syntax as [`?filter=` HTTP](db/filter.md)). |
| `--sort=<expr>` | Sort (grammar `[-]field1,[-]field2`). |
| `--limit=<n>` | Pagination limit. |
| `--offset=<n>` | Pagination offset. |
| `--skin=<name>` | Projection *skin*. |

## Bulk injection pattern

For *seeding* in development, the recommended pattern is a JSON *fixture* consumed by a *shell* loop + the `insert` action:

```bash
jq -c '.[]' fixtures/users.json | while read -r doc; do
    php api/bin/console.php users insert --data="$doc"
done
```

A native `import` action (consuming a JSON array in a single command, with transaction) is on the roadmap.

## `harvest` action

`DocumentsCommandHarvest` is not CRUD: it is an **extension point** to synchronize a collection from an external source (ERP, third-party API, flat file). The standard pattern is to subclass `DocumentsCommand` and provide a custom implementation of the `harvest` action.

Typical use case:

```bash
# Harvest products from an external source (e.g. ERP via ODBC)
php api/bin/console.php proginov:harvest:products

# Harvest pricing offers
php api/bin/console.php proginov:harvest:products:offers
```

See the `Acme\commands\proginov\*` commands for complete implementations on the project side.

## Enum catalog

| Enum | Role |
|---|---|
| `ArangoAction` | Actions available on `ArangoCommand` (`dump`, `restore`, `list-dumps`). |
| `ArangoCommandParam` | DI configuration keys for `ArangoCommand` (`NAME`, `OPTIONS`, ...). |
| `DocumentsCommandAction` | Actions available on `DocumentsCommand` (the 12 listed above). |
| `DocumentsCommandParam` | DI configuration keys for `DocumentsCommand` (`NAME`, `MODEL`). |
| `DocumentsCommandOption` | Specific CLI options (`--data`, `--key`, ...). |
| `ArangoCommandOption` | Global shared CLI options enum. |
| `ArangoCommonOption` | Options common to all Arango commands. |
| `ArangoDumpOption` / `ArangoRestoreOption` | Options specific to dump and restore. |

All these enums consume `ConstantsTrait` ([`oihana/php-enums`](getting-started/dependencies.md#oihanaphp-enums)) and can be inspected at runtime via `keys()` / `values()`.

## Utility traits

| Trait | Brings |
|---|---|
| `ArangoConfigTrait` | Hydration of the `ArangoConfig` on the command side. |
| `ArangoDumpTrait` | Mechanics of serializing a collection to a dump. |
| `ArangoRestoreTrait` | Mechanics of reinjecting a dump. |
| `DocumentsCommandTrait` | Shares behaviors between Documents actions. |
| `DocumentsCommandParamTrait` | Shared constants registry (`ConstantsTrait` + `CommandParamTrait`) for `DocumentsCommandParam`. |

## Registration in the commands registry

Every DI-defined command must be referenced in `definitions/commands.php` to be loaded by `bin/console.php`. Project convention:

```php
// api/definitions/commands.php
return
[
    Commands::ARANGODB ,
    Commands::USERS    ,
    Commands::ROLES    ,
    Commands::PRODUCTS ,
    // ...
] ;
```

Forgetting this registry = command not found on the CLI side ("command 'users' is not defined"). For `bun` commands, the alias must also be registered in `package.json`.

## See also

- [`Documents` and `Edges` models](models.md) — the business layer consumed by commands.
- [Slim controllers](controllers/README.md) — parallel HTTP exposition of the same operations.
- [HTTP filters `?filter=`](db/filter.md) — `--filter=<json>` syntax on the CLI side.
- [Dependencies — `oihana/php-commands`](getting-started/dependencies.md#oihanaphp-commands) — Symfony Console skeleton.
