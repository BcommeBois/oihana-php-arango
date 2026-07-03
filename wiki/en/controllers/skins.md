# Skins

## What is a *skin*?

A *skin* is a **projection variant** of a resource: the same document can be returned in several shapes depending on the consumer's need. It is the *output* counterpart of the *payload* on *input* — instead of describing what the client sends, the *skin* describes what the server returns.

A few concrete examples on a `User` resource:

- *"on a **list** of users (`GET /users`), return only `_key`, `email`, `name` and `roles[]`"* — that's the `default` *skin*;
- *"on a single **detail page** (`GET /users/{id}`), return all that PLUS `permissions[]`, `sessions[]`, `activities[]` and the last login"* — that's the `full` *skin*;
- *"on the **internal** projection consumed by a server middleware, add `tokensInvalidBefore` which must NEVER leave the server"* — that's the `internal` *skin*;
- *"on a search *autocomplete*, return only `_key` + `name`"* — typically a `compact` or `list` *skin*.

The client requests a specific *skin* through the `?skin=full` URL parameter. The controller validates it is authorized then passes it to the model, which filters the fields and relations to include in the response.

## Why a *skin* system

Without *skins*, you have two mediocre options:

1. **Always return everything** — costly in bandwidth, in query time (every join costs), and exposes fields you would have preferred to hide by default.
2. **Multiply endpoints** — `/users/list`, `/users/full`, `/users/compact`, `/users/with-roles`... unmanageable as soon as you have a dozen resources.

The framework's *skin* system solves the problem with **a single endpoint** per resource and a **parameterized projection**. Fields and relations are annotated (`Field::SKINS`, `AQL::SKIN_FIELDS`) on the model side to declare in which *skins* they appear; the controller declares which *skins* are acceptable on which HTTP verb. At *runtime*, the pipeline filters automatically.

## What this page covers

This page documents the **controller layer** of the *skin* system:

- The **catalog** of canonical *skins* (`Skin::DEFAULT`, `Skin::FULL`, ...).
- The **configuration keys** in the controller's DI definition (`Arango::SKINS`, `Arango::SKIN_DEFAULT`, `Arango::SKIN_METHODS`).
- The **runtime selection** via `?skin=` and the associated hooks (`PrepareSkin` trait).
- The **`Skin::INTERNAL` special case** — strictly server projection, not HTTP-exposable.

For the actual **field projection** (how `Field::SKINS` and `AQL::SKIN_FIELDS` filter fields and relations on the model side), see [Edge and join projection](../edges-joins-projection.md). That is the model layer, complementary to the one described here.

## Canonical *skin* catalog

The [`oihana\controllers\enums\Skin`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/enums/Skin.php) enum provides 12 canonical values. Business controllers and models can reuse them freely, and add their own *skins* through the local enum (`Acme\enums\Skin` extends `SkinTrait`).

| `Skin::*` | Typical semantics |
|---|---|
| `DEFAULT` | Compact projection for listings (`GET /resource`). Scalar fields + counted relations (`rolesCount`), no hydrated relations. |
| `FULL` | Full projection for the detail page (`GET /resource/{id}`). All fields + hydrated relations + sub-edges. |
| `MAIN` | Minimal projection of a sub-document reached through an *edge* — used to **break INBOUND cycles** (see [edges-joins-projection.md](../edges-joins-projection.md#breaking-an-inbound-cycle-with-aqlskin)). Without `Field::SKINS`, no sub-edge is followed. |
| `INTERNAL` | **Strictly server** projection: adds sensitive fields consumed by middlewares (e.g. `tokensInvalidBefore` for revocation). **Never** exposed over HTTP — see the dedicated section. |
| `COMPACT` | Ultra-short `LIST` variant for *autocomplete* (`_key` + `name` typically). |
| `EXTEND` | Like `FULL` but adds expensive derived data (statistics, aggregates). |
| `LIST` | Common synonym of `DEFAULT` when you want to be explicit. |
| `NORMAL` | Rare catch-all, to avoid — prefer `DEFAULT`. |
| `AUDIOS` / `PHOTOS` / `VIDEOS` | Specialized *skins* for media resources — project only the appropriate sub-collection. |
| `MAP` | Geo *skin* — projects only coordinates + identifier. |

`AUDIOS`/`PHOTOS`/`VIDEOS`/`MAP` only make sense on resources that consume them. On a `User` resource, declaring `Arango::SKINS => [ Skin::PHOTOS ]` has no useful effect.

## Controller-side configuration

Three keys in the controller's DI definition, all consumed by the [`PrepareSkin`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/traits/prepare/PrepareSkin.php) trait:

| Key | Type | Role |
|---|---|---|
| `Arango::SKINS` | `string[]` | **Whitelist** of *skins* acceptable on this controller. Any `?skin=` value outside the list is silently replaced with the default *skin*. |
| `Arango::SKIN_DEFAULT` | `string` | *Skin* applied when `?skin=` is absent and the HTTP verb has no dedicated *skin* in `SKIN_METHODS`. |
| `Arango::SKIN_METHODS` | `array<string,string>` | Different default *skin* **per HTTP verb**. Maps `HttpMethod::list => Skin::DEFAULT`, `HttpMethod::get => Skin::FULL`, etc. |

Typical example:

```php
use DI\Container ;
use oihana\arango\controllers\DocumentsController ;
use oihana\arango\enums\Arango ;
use oihana\controllers\enums\Skin ;
use oihana\api\enums\HttpMethod ;

return
[
    Controllers::USERS => fn( Container $c ) => new DocumentsController( $c ,
    [
        Arango::MODEL        => Models::USERS               ,
        Arango::SKINS        => [ Skin::DEFAULT , Skin::FULL ] ,
        Arango::SKIN_DEFAULT => Skin::DEFAULT                ,
        Arango::SKIN_METHODS =>
        [
            HttpMethod::list => Skin::DEFAULT ,
            HttpMethod::get  => Skin::FULL    ,
        ] ,
    ]) ,
] ;
```

Reading:

- `GET /users` (verb `list`) → *skin* `default` by default, or `?skin=full` if the client requests it.
- `GET /users/{id}` (verb `get`) → *skin* `full` by default, or `?skin=default` if the client prefers.
- `GET /users?skin=internal` → silently replaced with `default` (not in `SKINS`).

## Model-side configuration

The controller only decides **which *skins* are acceptable** on which route. The **actual content** of each *skin* — which fields appear, which disappear, which relations are hydrated — is declared **on the model side**, in the same DI definition that configures the ArangoDB collection.

Two keys attach a *skin* to a field or to an alternative projection:

### `Field::SKINS` — a field visible in some *skins*

On an individual field of `AQL::FIELDS`, the `Field::SKINS` marker declares the **list of *skins* that activate the field**. A field without marker is visible in all *skins*. A field marked `Skin::FULL` only appears in the `full` projection. The marker is honored at **every depth**: it can also be placed on a nested sub-field of a `Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP` — see [`Field::SKINS` in depth](../edges-joins-projection.md#fieldskins-in-depth--nested-sub-fields-filtermap--filterdocument--filterwrap).

```php
use oihana\arango\enums\AQL    ;
use oihana\arango\enums\Field  ;
use oihana\arango\enums\Filter ;
use Acme\enums\Skin       ;

Models::USERS => fn( Container $c ) => new Documents( $c ,
[
    AQL::COLLECTION => 'users'           ,
    AQL::DATABASE   => Databases::ARANGO ,
    AQL::SCHEMA     => User::class       ,

    AQL::FIELDS =>
    [
        // Scalar fields — visible everywhere (no marker)
        Prop::_KEY      => Filter::DEFAULT  ,
        Prop::EMAIL     => Filter::DEFAULT  ,
        Prop::NAME      => Filter::DEFAULT  ,
        Prop::CREATED   => Filter::DATETIME ,

        // Counts visible only in `default` (list)
        Prop::ROLES_COUNT =>
        [
            Field::FILTER => Filter::EDGES_COUNT ,
            Field::SKINS  => [ Skin::DEFAULT ]   ,
        ] ,

        // Hydrated relations — visible only in `full` (detail page)
        Prop::ROLES =>
        [
            Field::FILTER => Filter::EDGES ,
            Field::SKINS  => [ Skin::FULL ] ,
        ] ,
        Prop::PERMISSIONS =>
        [
            Field::FILTER => Filter::EDGES ,
            Field::SKINS  => [ Skin::FULL ] ,
        ] ,

        // Sensitive field — visible only in `internal` (server-only)
        Prop::TOKENS_INVALID_BEFORE =>
        [
            Field::FILTER => Filter::DATETIME ,
            Field::SKINS  => [ Skin::INTERNAL ] ,
        ] ,
    ] ,
]) ,
```

Reading:

- `GET /users` (*skin* `default`) returns `_key`, `email`, `name`, `created`, `rolesCount`. No `roles[]` nor `permissions[]` (too heavy for a list).
- `GET /users/{id}` (*skin* `full`) returns all of the above **without** `rolesCount` (hidden by the marker) **plus** `roles[]` and `permissions[]` hydrated.
- `usersModel->get([ Arango::SKIN => Skin::INTERNAL ])` on the server side returns all of the above **plus** `tokensInvalidBefore`. No HTTP client can reach this projection (see [`Skin::INTERNAL`](#the-skininternal-special-case)).

The marker accepts three forms: array of *skins*, comma-separated string (`'main,full'`), or `null` (equivalent to no marker).

### `AQL::SKIN_FIELDS` — massive alternative projections

When the projection differs **largely** between two *skins* (fields added, different relations, restructured sub-objects), putting `Field::SKINS` everywhere becomes unreadable. The `AQL::SKIN_FIELDS` key declares several distinct field sets, selected at *runtime* depending on the *skin*:

```php
AQL::SKIN_FIELDS =>
[
    Skin::DEFAULT => [ /* flat list projection */          ] ,
    Skin::FULL    => [ /* rich projection with edges */    ] ,
    Skin::COMPACT => [ /* minimal projection */            ] ,
    '*'           => [ /* generic fallback */              ] ,   // optional
] ,
```

The table is accepted at **three levels**: on an *edge*/*join* definition (e.g. a `Role` that exposes its `permissions[]` only on the user detail page in `full`), at the **model root** (the light list vs the full record, without per-field markers), and on a **structural sub-field** `MAP`/`DOCUMENT`/`WRAP` (two shapes for the same nested key). See [Edge and join projection — `AQL::SKIN_FIELDS`](../edges-joins-projection.md#alternative-projection-per-skin--aqlskin_fields) for the full semantics and resolution rules.

### The parallel model ↔ controller contract

Both layers work as a **strict pair**:

| Layer | Responsibility | DI key(s) |
|---|---|---|
| **Controller** | Which *skins* are **accepted** by the URL and which one is the default per verb. | `Arango::SKINS`, `Arango::SKIN_DEFAULT`, `Arango::SKIN_METHODS` |
| **Model** | Which **fields and relations** appear in each *skin*. | `Field::SKINS` on `AQL::FIELDS`, `AQL::SKIN_FIELDS` on *edges* / *joins*, the model root or a structural sub-field |

Without one of the two layers, the system does nothing: a controller that accepts `?skin=full` without a model that changes its projection always returns the same fields; a model rich in `Field::SKINS` without a controller propagating the value always returns the default *skin*.

## Runtime selection — `?skin=`

The `PrepareSkin` pipeline runs **before** `beforeModelCall` and sets `$init[ Arango::SKIN ]` to the selected value:

```
HTTP request
  → PrepareSkin::prepareSkin($request, $init)
    1. Read ?skin= from the query string
    2. If absent → use SKIN_METHODS[$verb] or SKIN_DEFAULT
    3. If present but outside SKINS → use the default (silently)
    4. Write the selected value into $init[ Arango::SKIN ]
  → beforeModelCall($request, &$init)
  → model->get/list/...($init)
    The model filters Field::SKINS on that value
  → response
```

On the model side, the *skin* value is propagated to `returnFields` and `buildVariables` which apply `Field::SKINS` and `AQL::SKIN_FIELDS`. All this is documented in detail in [Edge and join projection](../edges-joins-projection.md).

## The `Skin::INTERNAL` special case

`Skin::INTERNAL` is a **strictly server projection**. It exposes sensitive fields consumed by middlewares (e.g. `tokensInvalidBefore` for session revocation, the SHA-256 hash of a pending email change verification code, etc.) but which must **never** transit over HTTP, not even for a superadmin.

### Golden rule

`Skin::INTERNAL` **must never** be present in a controller's `Arango::SKINS`, nor have an associated Casbin permission.

```php
// Wrong — authorizes ?skin=internal on the URL side
Arango::SKINS => [ Skin::DEFAULT , Skin::FULL , Skin::INTERNAL ] ,
```

The security guarantee rests on **one single invariant**: as long as `INTERNAL` is not listed in `Arango::SKINS`, the `PrepareSkin::isValidSkin` filter rejects `?skin=internal` and falls back to the default projection. No HTTP caller can therefore force that projection.

**No Casbin permission either, by design.** Creating `users:skin.internal` for instance would allow a superadmin to grant it to an account via `POST /users/{id}/permissions/{permKey}` and break the invariant in a single request.

### Server-side usage

Server middlewares call the model directly, **bypassing the HTTP layer**:

```php
$user = $this->usersModel->get
([
    Arango::ID   => $userKey      ,
    Arango::SKIN => Skin::INTERNAL ,
]) ;
```

The *capabilities* framework ([capabilities.md](capabilities.md)) lives on the HTTP controller layer, **not** on the model. Direct calls to the model are therefore not restricted — they remain trusted because they come from server PHP code, not from user *input*.

For the full pattern detail and the list of `INTERNAL` fields currently in place, see [Tips and pitfalls — `Skin::INTERNAL`](../tips.md#skininternal--server-only-projection).

## Complete example — choosing a resource's *skins*

Complete definition for the `users` resource, with a business *skin* added next to the canonicals:

```php
use Acme\enums\Skin ;     // extends SkinTrait — exposes business values

Controllers::USERS => fn( Container $c ) => new DocumentsController( $c ,
[
    Arango::MODEL => Models::USERS ,

    Arango::SKINS =>
    [
        Skin::DEFAULT  ,   // compact listing
        Skin::FULL     ,   // full detail with roles + permissions
        Skin::COMPACT  ,   // autocomplete (_key + email + name)
    ] ,

    Arango::SKIN_DEFAULT => Skin::DEFAULT ,

    Arango::SKIN_METHODS =>
    [
        HttpMethod::list => Skin::DEFAULT ,
        HttpMethod::get  => Skin::FULL    ,
    ] ,
]) ,
```

On the model side, the actual projection of each *skin* is controlled by `Field::SKINS` on the fields and `AQL::SKIN_FIELDS` on the *edges* — cf. [Edge and join projection](../edges-joins-projection.md).

## See also

- [Controllers overview](README.md) — full pipeline, lifecycle hooks.
- [Edge and join projection](../edges-joins-projection.md) — model layer: `Field::SKINS`, `AQL::SKIN_FIELDS`, `AQL::SKIN`.
- [Tips and pitfalls — `Skin::INTERNAL`](../tips.md#skininternal--server-only-projection) — golden rule in detail + current use cases.
- [Capabilities](capabilities.md) — orthogonal system that can restrict the **value** of a *skin* to a Casbin permission (`Capability::PARAMS`).
- [Enums reference](../enums.md) — `Skin`, `Arango::*`.
