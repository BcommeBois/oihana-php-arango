# Casbin RBAC adapter

The [`src/oihana/arango/casbin/`](../../src/oihana/arango/casbin/) folder contains a single class: `ArangoCasbinAdapter`. It adapts the [Casbin](https://casbin.org/) engine (RBAC / ABAC) to an ArangoDB collection by going through a standard framework [`Documents`](models.md) model.

## Why a dedicated adapter

Casbin handles the **logic** of authorization (RBAC / ABAC models, *enforcer*, *matcher* policies). It knows nothing about storage: it consumes an *adapter* to persist, read, update *policies*. The default official adapter is file-based (CSV); for a multi-tenant, multi-user application, or one that dynamically modifies its policies, a database *adapter* is needed.

`ArangoCasbinAdapter` plays this role:

- **Persistence** of *policies* in an ArangoDB collection (typically `rbac`).
- **Loading** at *enforcer* startup or on demand (filtered mode).
- **Consistency** with the rest of the framework — `bind variables`, `_key` handling, DI integration.
- **Full Casbin compatibility**: implements the four standard interfaces.

## Casbin interfaces implemented

The adapter implements **all the Casbin persistence interfaces**:

| Interface | Brings |
|---|---|
| `Casbin\Persist\Adapter` | Base API: `loadPolicy`, `savePolicy`, `addPolicy`, `removePolicy`, `removeFilteredPolicy`. |
| `Casbin\Persist\BatchAdapter` | `addPolicies`, `removePolicies` in batch (bulk insert / delete). |
| `Casbin\Persist\FilteredAdapter` | `loadFilteredPolicy` — loads a *policy* subset through a filter (useful in multi-tenant). |
| `Casbin\Persist\UpdatableAdapter` | `updatePolicy`, `updateFilteredPolicies` — atomic updates. |

Concretely, any standard Casbin `Enforcer` can use this adapter without modification.

## Composition

The adapter doesn't contain its own data access layer. It **consumes a `Documents` model** configured on the RBAC collection. This guarantees:

- A **single source of truth** for ArangoDB access conventions (bind, validation, hooks).
- **Consistency** between *policies* written by Casbin and the read queries done by the rest of the application (a manual *dump* of a *policy* uses the same tools).
- **Portability**: if you change the target collection or the fields, you modify the model definition, not the adapter.

```php
public function __construct
(
    Documents|DocumentsModel $model  ,
    ?LoggerInterface         $logger = null
)
```

## Structure of a Casbin *policy* in ArangoDB

Casbin uses a key/value structure with seven possible slots per *policy*:

```php
public const array KEYS = [ 'ptype' , 'v0' , 'v1' , 'v2' , 'v3' , 'v4' , 'v5' ] ;
```

| Key | Role |
|---|---|
| `ptype` | Policy type (`p` for permission, `g` for group, etc.). |
| `v0` | First element (subject — user or role). |
| `v1` | Second element (object — resource). |
| `v2` | Third element (action — HTTP verb, business operation). |
| `v3` to `v5` | Optional elements — multi-tenant domain, context, conditions. |

A Casbin document in the `rbac` collection looks like:

```json
{
    "_key": "p:alice:users:read",
    "ptype": "p",
    "v0":    "alice",
    "v1":    "users",
    "v2":    "read"
}
```

The adapter maps between this structure and the Casbin API. The `_key` format is defined by convention — typically `<ptype>:<v0>:<v1>:<v2>`.

## DI definition

```php
use DI\Container ;
use Casbin\Enforcer ;
use oihana\arango\casbin\ArangoCasbinAdapter ;
use Psr\Log\LoggerInterface ;

return
[
    // Documents model on the rbac collection
    Models::RBAC => fn( Container $c ) => new Documents( $c ,
    [
        AQL::COLLECTION => 'rbac'             ,
        AQL::DATABASE   => Databases::ARANGO  ,
        AQL::FIELDS     =>
        [
            Prop::_KEY => Filter::DEFAULT ,
            'ptype'    => Filter::DEFAULT ,
            'v0'       => Filter::DEFAULT ,
            'v1'       => Filter::DEFAULT ,
            'v2'       => Filter::DEFAULT ,
            'v3'       => Filter::DEFAULT ,
            'v4'       => Filter::DEFAULT ,
            'v5'       => Filter::DEFAULT ,
        ] ,
    ]) ,

    // Casbin adapter plugged into the model
    Services::CASBIN_ADAPTER => fn( Container $c ) => new ArangoCasbinAdapter
    (
        $c->get( Models::RBAC )                ,
        $c->get( LoggerInterface::class )
    ) ,

    // Standard Casbin Enforcer that consumes the adapter
    Services::CASBIN_ENFORCER => fn( Container $c ) => new Enforcer
    (
        '/path/to/rbac_model.conf'             ,
        $c->get( Services::CASBIN_ADAPTER )
    ) ,
] ;
```

The Casbin `Enforcer` is then consumed throughout the application via:

```php
$enforcer = $container->get( Services::CASBIN_ENFORCER ) ;

if ( $enforcer->enforce( $userKey , 'users' , 'read' ) )
{
    // authorized
}
```

## *Policy* synchronization from *edges*

A typical convention: Casbin *policies* are **derived** from a canonical state stored in *edges* (relations between `users` ↔ `roles`, `roles` ↔ `permissions`, etc.). A dedicated command materializes the *edges* into *policies*:

```bash
php bin/console.php auth:sync:policies
```

The flow:

1. Read the canonical *edges* (`user_has_roles`, `role_has_permissions`, `policy_has_permissions`).
2. Compute the set of equivalent Casbin tuples.
3. Wipe the `rbac` collection.
4. Insert the new tuples in batch via `BatchAdapter::addPolicies()`.

Advantage: a single *source of truth* (the *edges*), a denormalized view (the Casbin *policies*) regenerable on demand.

## Known pitfalls

### `savePolicy()` resets everything

`Adapter::savePolicy()` is designed to **write all** *policies* at once. If you call it accidentally (e.g. by wiring `Enforcer::savePolicy()` at the end of an operation), you lose all *policies* in DB and only write those currently in the *enforcer*'s memory.

Golden rule: **never call `savePolicy()` on the application side**. Use `addPolicy()` / `removePolicy()` / `updatePolicy()` which are incremental.

### `loadFilteredPolicy()` and `filtered` state

When `loadFilteredPolicy()` is called, the internal `$filtered` attribute switches to `true`. From this point, the *enforcer* knows it only has a partial view of the database and **refuses** `savePolicy()` (which prevents the overwrite described above).

To switch back to full mode: call `loadPolicy()` (without filter).

### `_key` conflicts

A *policy*'s `_key` must be unique by `<ptype>:<v0>:<v1>:<v2>` convention. Trying to add two identical *policies* throws `Error409` (ArangoDB conflict). The adapter doesn't deduplicate internally — it's the business layer (typically the `auth:sync:policies` command) that must guarantee uniqueness.

### `safeSubject` for identifiers

Any subject (`v0`) passed to the *enforcer* (read or write) must go through `casbinSafeSubject()` to avoid Casbin's silent coercion on some characters. See [Authentication tips](https://github.com/BcommeBois/oihana-php-auth/blob/main/wiki/en/tips.md) in `oihana/php-auth`.

## See also

- [`Documents` and `Edges` models](models.md) — the business layer consumed by the adapter.
- [Field projection](projection.md) — `AQL::REQUIRES` exploited by the Casbin authorization pattern.
- [Slim controllers — `InjectAuthorizerTrait`](controllers/README.md#injectauthorizertrait) — injection of a `Closure(string $subject): bool` *callable* based on the *enforcer*.
- [Official Casbin documentation](https://casbin.org/docs/overview).
- [Official Casbin PHP documentation](https://github.com/php-casbin/php-casbin).
