# Dependencies

`oihana/arango` relies on a small set of `oihana/php-*` packages that cover the building blocks (enums, exceptions, reflection, files) and cross-cutting layers (system, signals, commands). This page lists the exact dependencies observed in the code, their role, and provides a minimal `composer require` snippet according to the sub-modules actually used.

> All `oihana/*` dependencies are currently versioned as `dev-main`. Stabilization will happen by cascade once the whole graph is ready for tagging. See the final note.

## Overview

The table below reflects the `use oihana\...` *imports* actually present in the code of `api/src/oihana/arango/` at the time of writing. The number of imports gives an idea of each package's centrality.

| Package | Root namespace | Imports | Role |
|---|---|---|---|
| `oihana/php-enums` | `oihana\enums\` | 147 | Typed constants and `ConstantsTrait` (`AQL::*`, `Operator::*`, ...) |
| `oihana/php-exceptions` | `oihana\exceptions\` | 132 | `ValidationException` and the standard exception family |
| `oihana/php-reflect` | `oihana\reflect\` | 74 | Introspection (`getPublicProperties`, `hasTrait`, `useConstantsTrait`, ...) |
| `oihana/php-system` | `oihana\` (wide) | 99 | Base controllers, base models, cross-cutting traits, logging |
| `oihana/php-commands` | `oihana\commands\` | 37 | Symfony Console skeleton for `ArangoCommand` and `DocumentsCommand` |
| `oihana/php-files` | `oihana\files\` + `oihana\options\` | 18 | File reading, serializable options |
| `oihana/php-core` | `oihana\core\` | 3 | Foundation helpers (`oihana\core\strings\compile`, `resolveCallable`, ...) |
| `oihana/php-signals` | `oihana\signals\` | 2 | Application signals (cascade via `afterDelete`) |

> The `oihana/php-system` package has a wide *autoload* (`"oihana\\" => "src/oihana"`): it actually provides several root namespaces — `oihana\controllers\`, `oihana\models\`, `oihana\traits\`, `oihana\logging\`. The 99 imports break down as: `controllers` (45), `models` (41), `traits` (12), `logging` (1).

## Detail per package

### `oihana/php-enums`

- Namespace: `oihana\enums\`
- Role: provides `ConstantsTrait` (introspection `keys()` / `values()`) and the framework's common enum convention. All `AQL`, `Arango`, `Filter`, `Operator`, `Comparator`, `Skin`, etc. classes are based on this trait.
- Centrality: **maximum**. No `oihana/arango` module works without this package.

### `oihana/php-exceptions`

- Namespace: `oihana\exceptions\`
- Role: foundation of cross-cutting exceptions (`ValidationException`, `UnauthorizedException`, `NotFoundException`, ...). `Documents` models throw these standardized exceptions on any validation, lookup or consistency failure.
- Centrality: **maximum**. All modules use it.

### `oihana/php-reflect`

- Namespace: `oihana\reflect\`
- Role: lightweight introspection. `useConstantsTrait()` detects whether a class consumes `ConstantsTrait`, `getPublicProperties()` enumerates a schema's public properties, `hasTrait()` validates a model's composition. Used by the models layer to hydrate and validate without raw `ReflectionClass`.
- Centrality: **high** (74 imports), mostly in `models/` and `db/`.

### `oihana/php-system`

- Namespaces: `oihana\controllers\`, `oihana\models\`, `oihana\traits\`, `oihana\logging\` (wide `oihana\` autoload).
- Role: base of HTTP controllers (`Controller`, `StatusTrait`, `PrepareSkin`, `ModelCallTrait`, ...), base of cross-cutting models (above which `Documents` and `Edges` compose), PSR-3 `LoggerTrait`.
- Centrality: **high** (99 imports). Required as soon as you consume `arango/controllers/`, `arango/models/` or the Slim CRUD controller.

### `oihana/php-commands`

- Namespace: `oihana\commands\`
- Role: Symfony Console skeleton (enriched `Command` class, I/O traits, output formats). `ArangoCommand` and `DocumentsCommand` extend it to expose the ArangoDB CRUD on the CLI.
- Centrality: **medium** (37 imports). Required for `arango/commands/` only.

### `oihana/php-files`

- Namespaces: `oihana\files\` + `oihana\options\`
- Role: file reading (`readJson`, `readToml`) and serializable `Options` classes that underpin the AQL `*Options` (`QueryOptions`, `InsertOptions`, ...).
- Centrality: **moderate** (18 imports), concentrated in `db/options/`.

### `oihana/php-core`

- Namespace: `oihana\core\`
- Role: foundation helpers — `oihana\core\strings\compile()` (join fragments while skipping empties), `oihana\core\callables\resolveCallable()` (Closure / `[obj, method]` / `'Class::method'` resolution), `oihana\core\arrays\*` (array utilities).
- Centrality: **low on imports** (3) but **structural**: the helpers consumed sit at the core of several algorithms (sort composition, *authorizer* resolution).

### `oihana/php-signals`

- Namespace: `oihana\signals\`
- Role: lightweight application signal bus. `oihana/arango` uses it for the **automatic cascade of relations**: a `Documents::delete()` emits an `afterDelete` signal that `EdgesFromTrait` and `EdgesToTrait` intercept to purge related edges.
- Centrality: **low on imports** (2) but **functionally critical** as soon as `AQL::EDGES` is declared on a model.

## Cross-cutting dependencies (non `oihana/*`)

`oihana/arango` does not pull any heavy dependency on its own, but some sub-modules integrate with third-party frameworks that the host project must provide:

| Sub-module | Expected external dependency | Notes |
|---|---|---|
| `arango/controllers/` | `slim/slim` (Slim 4) + a PSR-11 container | The controller consumes `Psr\Http\Message\ServerRequestInterface` and `Psr\Http\Message\ResponseInterface`. |
| `arango/commands/` | `symfony/console` | `DocumentsCommand` extends `Symfony\Component\Console\Command\Command` via `oihana/php-commands`. |
| `arango/casbin/` | `casbin/casbin` | `ArangoCasbinAdapter` implements `Casbin\Persist\Adapter`, `BatchAdapter`, `FilteredAdapter`. |
| `arango/client/` | none (the code is embedded) | *Legacy* fork of the official ArangoDB PHP driver. |

The PSR-11 container used throughout the examples is PHP-DI; nothing in `oihana/arango` is however tied to that implementation — any `Psr\Container\ContainerInterface` implementation works.

## Local couplings to the host project

Four remaining imports target an `oihana\api\*` namespace that does not belong to any vendor package: it lives under `api/src/oihana/api/` in `oihana-odbc-php`. These imports must be addressed before `oihana/arango` is extracted as a standalone library.

| Imported symbol | Location | Status |
|---|---|---|
| `oihana\api\auth\CapabilityEnforcer` | local `api/src/oihana/api/auth/` | to be abstracted or removed |
| `oihana\api\auth\PermissionSubjectResolver` | local `api/src/oihana/api/auth/` | to be abstracted or removed |
| `oihana\api\controllers\traits\DocumentsControllerCapabilitiesTrait` | local `api/src/oihana/api/controllers/traits/` | to be extracted or made optional |
| `oihana\api\controllers\traits\PermissionAuthorizerTrait` | local `api/src/oihana/api/controllers/traits/` | to be extracted or made optional |

These couplings are concentrated on the *capabilities* chain (Casbin permission gating). Three options to weigh at extraction time: (1) extract these traits into a dedicated `oihana/php-api-auth` package; (2) inject the authorization callable via the existing `Closure(string $subject): bool` contract and move these traits into the host project; (3) accept the dependency and publish `oihana/arango` with a Composer `suggest`.

## Minimal `composer require` snippet

For **full** use of `oihana/arango` (AQL layer + models + Slim controllers + CLI commands + Casbin):

```bash
composer require \
    oihana/php-enums:dev-main      \
    oihana/php-exceptions:dev-main \
    oihana/php-reflect:dev-main    \
    oihana/php-system:dev-main     \
    oihana/php-commands:dev-main   \
    oihana/php-files:dev-main      \
    oihana/php-core:dev-main       \
    oihana/php-signals:dev-main
```

For **minimal** use (`db/` AQL layer only, without models or controllers):

```bash
composer require \
    oihana/php-enums:dev-main      \
    oihana/php-exceptions:dev-main \
    oihana/php-reflect:dev-main    \
    oihana/php-core:dev-main       \
    oihana/php-files:dev-main
```

For optional sub-modules:

```bash
# Slim controllers
composer require slim/slim:^4.0 php-di/php-di:^7.0

# Symfony Console commands
composer require symfony/console:^6.0

# Casbin RBAC adapter
composer require casbin/casbin:^3.0
```

> `oihana/arango` itself is not yet published as a standalone package. Until the extraction takes place, the code lives under `api/src/oihana/arango/` in `oihana-odbc-php`. The `composer require oihana/arango:dev-main` snippet will become valid at that point.

## Note on versions

All `oihana/*` packages are currently versioned as `dev-main`. As long as one dependency of the graph is `dev-main`, the package that consumes it stays `dev-main` too — it would be incoherent to tag `1.0.0` a package that points at `dev-main`. Stabilization will happen by cascade once the whole graph is ready to receive a tag.

## See also

- [Introduction](introduction.md) — why this library exists.
- [Glossary](glossary.md) — framework terms.
- [Quickstart `ArangoDB`](quickstart.md) — first operational example.
