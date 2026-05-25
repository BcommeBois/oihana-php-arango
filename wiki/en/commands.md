# Symfony Console commands

The [`src/oihana/arango/commands/`](../../src/oihana/arango/commands/) folder exposes the [`Documents`](models.md) business layer on the **command line** side. Same CRUD operations as the HTTP controllers, accessible via `php bin/console.php <name>` or a `bun` alias on the project side.

Two pivot classes:

| Class | Role | Exposed actions |
|---|---|---|
| `ArangoCommand` | Database maintenance (dump, restore, list dumps). | `dump`, `restore`, `list-dumps` |
| `DocumentsCommand` | Full CRUD on a collection. | `get`, `list`, `count`, `exist`, `last`, `insert`, `update`, `replace`, `upsert`, `delete`, `truncate`, `harvest` |

Both inherit from the [`oihana/php-commands`](getting-started/dependencies.md#oihanaphp-commands) skeleton, which provides argument handling, options, output formats (JSON, table, raw) and return codes.

## `ArangoCommand`

### Available actions

| Action | Class | Typical output |
|---|---|---|
| `dump` | `ArangoDumpAction` | Export of one or several collections to an *archives* folder (`.dump.json`). |
| `restore` | `ArangoRestoreAction` | Reinjection of a previous dump into the database. |
| `list-dumps` | `ArangoListDumpsAction` | List of dumps available in the archives folder. |

Configured via the option arrays [`ArangoDumpOption`](../../src/oihana/arango/commands/options/ArangoDumpOption.php), [`ArangoRestoreOption`](../../src/oihana/arango/commands/options/ArangoRestoreOption.php), and the common [`ArangoCommonOption`](../../src/oihana/arango/commands/options/ArangoCommonOption.php) (folder path, verbosity, `--dry-run` mode, ...).

### DI definition

```php
use DI\Container ;
use oihana\arango\commands\ArangoCommand ;

return
[
    Commands::ARANGODB => fn( Container $c ) => new ArangoCommand( $c ,
    [
        ArangoCommandParam::NAME    => 'arangodb' ,
        ArangoCommandParam::OPTIONS =>
        [
            ArangoCommonOption::DUMPS_DIR => '/var/data/arango/dumps' ,
            // ...
        ] ,
    ]) ,
] ;
```

### CLI usage

```bash
# Dump
php api/bin/console.php arangodb dump --collection=users --collection=roles

# Restore
php api/bin/console.php arangodb restore --dump=users-2026-05-17.json

# List dumps
php api/bin/console.php arangodb list-dumps

# bun alias (host project side)
bun arangodb dump --collection=users
```

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

# bun alias (host project side)
bun users list --limit=20
bun users count
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
    bun users insert --data="$doc"
done
```

A native `import` action (consuming a JSON array in a single command, with transaction) is on the roadmap.

## `harvest` action

`DocumentsCommandHarvest` is not CRUD: it is an **extension point** to synchronize a collection from an external source (ERP, third-party API, flat file). The standard pattern is to subclass `DocumentsCommand` and provide a custom implementation of the `harvest` action.

Typical use case:

```bash
# Harvest products from the external ERP (ODBC)
bun proginov:harvest:products

# Harvest pricing offers
bun proginov:harvest:products:offers
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
