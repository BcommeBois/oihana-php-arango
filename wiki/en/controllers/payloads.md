# Payloads

## What is a *payload*?

When a client sends an HTTP request that creates or modifies a resource — typically `POST`, `PATCH` or `PUT` — it carries the new data in the request **body**, most often as JSON. We call this content the **payload**, literally "useful load": it's the actual application data, to be distinguished from *headers* (transport metadata, authentication) and URL parameters (`?filter=...&limit=...`).

Concrete example. To create a role through the API, the client sends:

```http
POST /roles HTTP/1.1
Content-Type: application/json
Authorization: Bearer <token>

{
    "name": "editor",
    "description": { "fr": "Éditeur", "en": "Editor" },
    "color": "#3498db",
    "level": 50
}
```

The trailing JSON block is the *payload*. To the server, it is **just a raw associative array**: no field is guaranteed to be present, no value is typed, and a malicious or simply buggy client can send anything (`{ "system": true }`, a number in `name`, a flat string in `description`...).

## Why a dedicated layer

Before a *payload* can be passed to the [`Documents` model](../models.md) for database insertion or update, it must be **disciplined**:

1. **Whitelist** — accept only the fields explicitly authorized for the target collection. Anything else is silently ignored (security against field injection: a client must not be able to write `_key`, `system`, `internalNotes`...).
2. **Typing** — convert an HTTP value (always string or JSON array) into a native PHP / AQL type: `int`, `bool`, `array`, or a typed `i18n` object.
3. **Transformation** — apply default values (`level: 0` if absent), forced values (`system: false` regardless of body), normalization functions (lowercase, *trim*, `_key` generation...).

As long as these three things are written as imperative code scattered across every controller, you have two problems: it's verbose, and it's easy to forget a step on a newly added endpoint. The `oihana/arango` framework groups them into a single **declarative structure** — a PHP array — in the controller's DI definition. The entire extraction, shape validation, typing and transformation machinery runs automatically off that declaration.

## What this page covers

The whole mechanism lives in [`PayloadsTrait`](../../../../api/src/oihana/arango/controllers/traits/PayloadsTrait.php), consumed by `DocumentsController`, `EdgesController` and `PropertyController`. The application configuration goes through the [`Arango::PAYLOAD`](../enums.md#aql) key in the controller's DI definition.

This page documents:

- The **format** of the `Arango::PAYLOAD` key (structure per HTTP method, per-field options).
- The **catalog of types** accepted by fields (`AQLType`).
- The full **lifecycle** from raw *body* to insert-ready document.
- Recurring **pitfalls** (`COMPRESS` for PATCH, `VALUE` vs `DEFAULT`, i18n validation).

For **value validation** as business constraints (regex, min/max length, *required*), see [Rules](rules.md) — a separate layer that applies **after** payload preparation.

## Cycle overview

```
HTTP body
  → enforceI18nShape($init)                       (422 if an i18n field is a flat string)
  → preparePayload($init, &$relations)            extraction + typing + alter
  → validator->validate($payload, $rules)         (cf. rules.md — 422 if invalid)
  → beforeModelCall($request, &$init)             user hook
  → model->insert/update($payload, $relations)    AQL write
  → afterModelCall($request, &$init, &$result)    user hook
  → response                                       final serialization
```

This page covers the **first two steps** (`enforceI18nShape` and `preparePayload`). `rules` validation is documented in [Rules](rules.md), hooks and *skins* in [README](README.md) and [Skins](skins.md).

## `Arango::PAYLOAD` definition format

The `Arango::PAYLOAD` key accepts a **double-indexed** array: by HTTP method, then by field name.

```php
Arango::PAYLOAD =>
[
    Arango::COMPRESS => [ HttpMethod::PATCH ] ,     // global option (see below)

    HttpMethod::ALL  => [ /* fields shared by all methods */ ] ,
    HttpMethod::POST => [ /* fields added / overridden on POST  */ ] ,
    HttpMethod::PATCH => [ /* fields added / overridden on PATCH */ ] ,
    HttpMethod::PUT   => [ /* fields added / overridden on PUT   */ ] ,
] ,
```

**Merge rule**: for a `POST` request, the framework concatenates `ALL` and `POST` via `array_merge` — keys defined in `POST` **replace** those defined in `ALL`. Same for `PATCH` and `PUT`.

### Short form vs extended form

For a field that just needs a type, the AQL type is written directly:

```php
HttpMethod::ALL => [
    Prop::NAME  => AQLType::STRING ,
    Prop::EMAIL => AQLType::STRING ,
] ,
```

For a field that needs extra options (`DEFAULT`, `VALUE`, `ALTER`, `PAYLOAD`, ...), switch to array form:

```php
HttpMethod::POST => [
    Prop::LEVEL  => [ Arango::TYPE => AQLType::INT , Arango::DEFAULT => 0 ] ,
    Prop::SYSTEM => [ Arango::VALUE => false ] ,    // forced value — body ignored
] ,
```

## `AQLType` catalog

The [`AQLType`](../../../../api/src/oihana/arango/controllers/enums/AQLType.php) enum declares the types recognized by `preparePayload`. The type controls two things: extraction (which `getParam*()` HTTP call to use) and typing on the output payload side.

| `AQLType::*` | Semantics | HTTP extractor used |
|---|---|---|
| `STRING` | Character string | `getParamString` |
| `INT` | Integer | `getParamInt` |
| `INT_WITH_RANGE` | Integer bounded by `MIN_RANGE` / `MAX_RANGE` | `getParamInt` + clamp |
| `FLOAT` | Float | `getParamFloat` |
| `FLOAT_WITH_RANGE` | Bounded float | `getParamFloat` + clamp |
| `BOOL` | Boolean | `getParamBool` |
| `NULL` | Forced `null` | — |
| `DATE` | ISO 8601 | `getParamString` + date validation |
| `ARRAY` | Flat array | `getParamArray` |
| `OBJECT` | Free object (associative) | `getParamArray` |
| `I18N` | Typed i18n object (`{ fr: ... , en: ... }`) | `getParamArray` + `filterLanguages` |
| `PAYLOAD` | Nested sub-payload (recursive) | `generatePayload` |
| `EDGE` | Vertex reference — stored in `$relations`, not in the payload | `_id`/`_key` extractor |
| `JOIN` | Simple reference to another document | similar to `EDGE` |
| `JOINS` | Array of references | similar to `EDGE` (multiple) |
| `DOCUMENT` | Full nested document | recursive |
| `MODEL` | Delegated to a named model | resolved from the DI container |
| `PATH` | AQL `<collection>/<key>` path | handle validation |

> Note: the enum lives under `oihana\arango\controllers\enums\AQLType` (not in `db/enums/`). Don't confuse with `Arango::TYPE` (the **configuration key** in the field definition) which *contains* an `AQLType` value.

## Field definition keys

When a field needs more than its type, switch to array form with these keys:

| Key | Type | Role |
|---|---|---|
| `Arango::TYPE` | `AQLType::*` | Field type. Required if in array form and no `Arango::VALUE` is set. |
| `Arango::VALUE` | `mixed` | **Fixed** value, completely ignores the body. Useful to force a flag (`system: false` on every HTTP POST). |
| `Arango::DEFAULT` | `mixed` | **Fallback** value if the field is absent from the body. The body can still provide an explicit value. |
| `Arango::NAME` | `string` | HTTP parameter name if different from the object key (rare). |
| `Arango::ALTER` | `callable\|array` | Transformation applied to the extracted value (lowercase, *trim*, *slugify*, custom...). |
| `Arango::PAYLOAD` | `array` | Nested sub-payload for `PAYLOAD`, `OBJECT`, `DOCUMENT` types. |
| `Arango::SANITIZE` | `bool\|array` | *Sanitization* options (removal of dangerous characters). |
| `FilterOption::MIN_RANGE` / `MAX_RANGE` | `int\|float` | Bounds for `INT_WITH_RANGE` / `FLOAT_WITH_RANGE`. |

**`VALUE` vs `DEFAULT`**: `VALUE` ignores the body, `DEFAULT` only kicks in if the body doesn't provide the key. Critical distinction for security — to force a field to `false` on HTTP (e.g. a CLI-only *flag*), use `VALUE`, not `DEFAULT`.

## Global `Arango::COMPRESS` option

At the same level as `HttpMethod::ALL/POST/PATCH/PUT`, the `Arango::COMPRESS` option controls whether `null` values are **stripped** from the final payload:

```php
Arango::PAYLOAD =>
[
    Arango::COMPRESS => [ HttpMethod::PATCH ] ,     // strip nulls on PATCH
    // ...
] ,
```

**Why it matters on PATCH**: without `COMPRESS`, any declared field absent from the body ends up as `null` in the payload. The AQL `UPDATE doc WITH { name: null, email: null }` wipes these fields in the database. Compression solves the problem by eliminating those keys from the final payload.

**Accepted values**:

- `false` or absent — no compression (default).
- `true` — compression on all methods.
- `[ HttpMethod::PATCH ]` or `[ HttpMethod::PATCH , HttpMethod::PUT ]` — compression only on those methods.

In practice: **always `[ HttpMethod::PATCH ]`** for standard CRUD controllers. A partial update must never wipe a field absent from the body.

## i18n pre-extraction validation

`AQLType::I18N` fields represent a typed object `{ fr: "...", en: "...", es: null, ... }`. A misbehaved client may send a flat string (`"description": "Hello"`) instead — without detection, this string ends up in the database and breaks every projection query.

`enforceI18nShape()` is called **before** `preparePayload()` and throws a 422 as soon as an i18n field doesn't have the expected object shape:

```php
// 422 response
{
    "errors":
    {
        "description": "must be an object with locale keys (fr, en, ...), not a flat string"
    }
}
```

It's a *fail-fast* that prevents silent database corruption. The `DocumentsControllerPost`/`Patch`/`Put` controllers call it automatically.

## `EDGE` type — relations as separate output

When a field is typed `AQLType::EDGE`, the extracted value **doesn't land in the payload** but in a separate `$relations` array, passed by reference:

```php
$payload = $this->preparePayload( $request , HttpMethod::POST , $init , $relations ) ;
// $payload   = [ name, email, color, ... ]                — document fields
// $relations = [ 'roles' => [ 'roles/123', 'roles/456' ] ] — edges to create after insertion
```

The `Documents::insert($payload, $relations)` model first inserts the document, then creates *edges* via *cascade*. Same for `update()` (which can add *edges* without modifying the document) and `delete()` (which purges *edges* via the `afterDelete` signal).

Related types: `JOIN` (simple reference without an edge collection), `JOINS` (multiple references).

## Recursion — nested `PAYLOAD` type

For structured sub-objects (address, coordinates, metadata), nest recursively:

```php
HttpMethod::ALL => [
    Prop::ADDRESS =>
    [
        Arango::TYPE    => AQLType::PAYLOAD ,
        Arango::PAYLOAD =>
        [
            HttpMethod::ALL =>
            [
                Prop::STREET      => AQLType::STRING ,
                Prop::CITY        => AQLType::STRING ,
                Prop::POSTAL_CODE => AQLType::STRING ,
                Prop::COUNTRY     => AQLType::STRING ,
            ] ,
        ] ,
    ] ,
] ,
```

`generatePayload()` is called recursively on each sub-block. `AQLType::PAYLOAD` can nest arbitrarily deep.

## Full example — `roles.php`

Annotated definition of the `roles` collection, which covers most real-world cases:

```php
Arango::PAYLOAD =>
[
    // Strip nulls on PATCH so the update stays truly partial.
    Arango::COMPRESS => [ HttpMethod::PATCH ] ,

    HttpMethod::ALL =>
    [
        Prop::NAME        => AQLType::STRING ,
        Prop::DESCRIPTION => AQLType::I18N   ,   // validated by enforceI18nShape
        Prop::COLOR       => AQLType::STRING ,
    ] ,

    HttpMethod::POST =>
    [
        Prop::LEVEL => [ Arango::TYPE => AQLType::INT , Arango::DEFAULT => 0 ] ,

        // CLI-only flags — HTTP creation forces false, regardless of body.
        Prop::SYSTEM    => [ Arango::VALUE => false ] ,
        Prop::PROTECTED => [ Arango::VALUE => false ] ,
        Prop::DEFAULT   => [ Arango::VALUE => false ] ,
    ] ,

    HttpMethod::PATCH =>
    [
        // LEVEL is mutable on PATCH but with no default (otherwise UPDATE would wipe).
        Prop::LEVEL => AQLType::INT ,
    ] ,
] ,
```

Reading:

- `POST /roles` accepts `name`, `description`, `color`, `level`. The `system`, `protected`, `default` *flags* are always `false`.
- `PATCH /roles/{id}` accepts `name`, `description`, `color`, `level`. Any `null` is stripped. CLI-only *flags* aren't **even in the whitelist** — a body `{ "system": true }` is silently ignored.
- `STRING` and `I18N` types are validated by the [Rules](rules.md) layer for length, regex, etc.

## `PayloadsTrait` public methods

For advanced cases (extension, custom hook, tests), the exposed public methods:

| Method | Role |
|---|---|
| `initializePayload( array $init ) : static` | Stores the `Arango::PAYLOAD` definition in `$this->payload`. Called in the controller constructor. |
| `preparePayload( ?Request , ?string $method , array $init , array &$relations ) : array` | Builds the payload from the HTTP body according to the definition. |
| `enforceI18nShape( ?Request , ?Response , ?string $method , array $init ) : ?Response` | Pre-validation: returns a 422 if an i18n field has a flat shape. |
| `validateI18nShape( ?Request , ?string $method , array $init ) : array` | Variant without response: returns `[]` or `[ field => errorMsg ]`. Useful for tests. |
| `propertyPayload( Request , ?string $property , array &$relations ) : mixed` | Variant for `PropertyController::patch` — extracts a single property. |
| `generatePayload( Request , ?array $definitions , array $args , array &$relations , bool $throwable ) : array` | Recursive core. Rarely called directly — `preparePayload` is the standard entry point. |

## See also

- [Controllers overview](README.md) — verb signatures, hooks, injection traits.
- [Rules](rules.md) — validation applied to the payload after preparation.
- [Skins](skins.md) — response projection (output, parallel to payload input).
- [`Documents` and `Edges` models](../models.md) — payload consumer (`insert`, `update`, `replace`).
- [Internal filtering](../filter-internal.md) — close pattern for server-only conditions.
- [Enums reference](../enums.md) — `AQLType`, `Arango::*`, `HttpMethod::*`.
