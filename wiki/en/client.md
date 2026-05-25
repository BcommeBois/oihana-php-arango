# *Legacy* ArangoDB client

The [`src/oihana/arango/client/`](../../src/oihana/arango/client/) folder is a **fork** of the [official ArangoDB PHP driver](https://github.com/arangodb/arangodb-php) (`triagens/ArangoDb`). It was absorbed as-is for two reasons:

1. **The official driver has not received a major update for several years** and doesn't support PHP 8.4 without patches.
2. No replacement community client covers the whole need (full driver compatibility + framework integration).

The fork has been **patched *minimally*** to work on PHP 8.4. It has **not** been refactored, modernized, or aligned with the rest of the framework's conventions. It lives in the `client/` folder and is consumed exclusively by the [`ArangoDB`](quickstart.md) class as an interface.

> This page is deliberately short. The code in the `client/` folder is *legacy* and slated to be replaced — it is not intended to become a stable API reference.

## Don't use it directly

The framework's contract is clear: **any interaction with ArangoDB goes through [`ArangoDB`](quickstart.md), the [`Documents` / `Edges` models](models.md), or the [controllers](controllers/README.md) / [commands](commands.md)**. The `client/` folder classes are implementation details.

**Incorrect** application code:

```php
use oihana\arango\client\Connection ;
use oihana\arango\client\DocumentHandler ;

$conn = new Connection( $options ) ;
$dh   = new DocumentHandler( $conn ) ;
$doc  = $dh->get( 'users' , 'abc' ) ;  // avoid
```

**Canonical** form:

```php
$users = $container->get( Models::USERS ) ;
$doc   = $users->get( [ Arango::ID => 'abc' ] ) ;
```

A direct dependency on `oihana\arango\client\*` in application code will be **broken** during the client rewrite (see [Roadmap](#rewrite-roadmap)) — while a call through `Documents` will survive.

## Pivot classes

The fork's classes are named like in the official driver. Documented here only for **mapping** purposes, to help read error traces or *stack traces*.

| Class | Role in the driver |
|---|---|
| `Connection` | HTTP connection to the ArangoDB server (*keep-alive* and retry handling). |
| `ConnectionOptions` | Connection options array (`OPTION_DATABASE`, `OPTION_ENDPOINT`, `OPTION_AUTH_TYPE`, ...). |
| `Statement` | Prepared AQL query — combines query text + bind variables. |
| `Cursor` | Iterator over a `Statement::execute()` result. |
| `DocumentHandler` | Low-level CRUD on documents (`get`, `save`, `update`, `remove`). |
| `EdgeHandler` | Same for *edges* (with `_from`/`_to` validation). |
| `CollectionHandler` | Low-level CRUD on collections (creation, *drop*, *truncate*, *rename*, indexes). |
| `Document` | Document representation (with `_key`, `_id`, `_rev`). |
| `Edge` | Edge representation (extends `Document`, adds `_from`, `_to`). |
| `EdgeDefinition` | Edge collection description in a *graph*. |
| `Graph` | Named *graph* representation (vertex + edge collections). |
| `BindVars` | Typed container for a `Statement`'s *bind variables*. |
| `Batch` / `BatchPart` | HTTP *batch* mode (multi-requests in one network transaction). |
| `Export` / `ExportCursor` | Bulk export API (sequential reading of a full collection). |
| `Transaction` | Embedded JavaScript transaction — deprecated in favor of *stream transactions*. |
| `StreamingTransaction` / `StreamingTransactionHandler` | Standard multi-document transactions. |
| `Exception` (and `ClientException`, `ServerException`, `ConnectException`, `FailoverException`) | Driver exception family. |
| `AdminHandler` | Administration endpoints (`/_admin/*`). |
| `FoxxHandler` | Foxx microservices management. |
| `AqlUserFunction` | User-defined AQL function definition. |
| `Analyzer` / `AnalyzerHandler` | Linguistic analyzers for ArangoSearch views. |
| `View` / `ViewHandler` | ArangoSearch views. |

Total: **56 classes** in the fork. None is documented page-by-page — for their detailed APIs, refer to the [official driver documentation](https://docs.arangodb.com/3.10/drivers/php/) (versions 3.10 / 3.11 — the last to have been updated).

## Rewrite roadmap

Eventually, the `client/` folder will be replaced with a standalone client written to the framework's standards:

- **Modern architecture** aligned with the latest ArangoDB version.
- **Zero *magic strings*** — all options and URLs go through enums.
- **Class restructuring** — clean separation between HTTP connection, *statement*, serialization.
- **Handler redesign** modeled on the high-level `Documents` / `Edges`.
- **Explicit interfaces** to allow *mocks* in tests.
- **Native PHP ≥ 8.4 compatibility**.
- **Complete unit + integration tests**.

No date is set — the rewrite will happen when the other framework pieces are stable and there's time for a standalone effort of this magnitude.

## What to do in the meantime

Three rules for the transition period:

1. **Always go through `ArangoDB` or a model** — never a direct import of `oihana\arango\client\*`.
2. **Report any fork bug** in a dedicated issue. An *upstream* fix on the official project will not be auto-applied.
3. **Don't tie yourself to fork signatures** — they will change during the rewrite, and the goal is that no application code needs to be modified for it (the public contract remains that of `ArangoDB` and the models).

## See also

- [Quickstart `ArangoDB`](quickstart.md) — the stable public API on top of this fork.
- [`Documents` and `Edges` models](models.md) — the business layer above.
- [Official ArangoDB PHP driver documentation](https://docs.arangodb.com/3.10/drivers/php/) — origin driver reference.
