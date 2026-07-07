# AQL field projection

This page describes the **projection** layer: which fields come out in the response, for which skin, under which permissions and transformations. For the **relations** — following an edge, resolving a stored reference (join), traversing a hierarchy, wrapping a result — see [Edge and join projection](edges-joins-projection.md): the two layers combine, and the mechanisms described here apply to relation projections as well.

## Table of contents

1. [Overview](#overview)
2. [The `Field::SKINS` marker at the document level](#the-fieldskins-marker-at-the-document-level)
3. [Per-request projection — `Field::SKINS` on sub-fields](#per-request-projection--fieldskins-on-sub-fields)
4. [Alternative projection per skin — `AQL::SKIN_FIELDS`](#alternative-projection-per-skin--aqlskin_fields)
5. [Which mechanism to use?](#which-mechanism-to-use)
6. [Permission-gated projections — `AQL::REQUIRES`](#permission-gated-edges-and-joins--aqlrequires)
7. [Transforming the projected value — `Field::ALTERS`](#transforming-the-projected-value--fieldalters)
8. [Internal reference — the `matchesSkin` helper](#internal-reference--the-matchesskin-helper)

## Overview

The AQL projection layer decides, for each HTTP request, which fields and which relations (edges, joins) to include in the response. The decision relies on three building blocks:

- the **request skin**: passed via `?skin=full`, `?skin=default`, or injected by the controller through `SKIN_METHODS` (defaulting to `default` for a list, `full` for a single GET);
- the **`Field::SKINS` markers** on the fields: declare the skins that activate the field;
- the **edge or join definition** in `AQL::EDGES` / `AQL::JOINS`: declares the projection of related documents.

The internal flow:

```
controller → model->get/list( SKIN ) → returnFields( $init )
   → prepareQueryFields( fields , skin )
      → filterFieldsBySkin( fields , skin )   ← matchesSkin against Field::SKINS
   → buildVariables( fields , edges , joins )
      → buildEdgeVariable( definition )       ← edge projection
      → buildJoinVariable( definition )       ← join projection
```

You never call `matchesSkin` or the builders directly. You declare your intent through `Field::SKINS`, `AQL::FIELDS`, `AQL::EDGES`, `AQL::SKIN`, `AQL::SKIN_FIELDS` in the container definitions.

## The `Field::SKINS` marker at the document level

On a `Documents` model field, `Field::SKINS` declares the list of skins that activate the field.

```php
AQL::FIELDS =>
[
    Prop::_KEY        => Filter::DEFAULT ,
    Prop::EMAIL       => Filter::DEFAULT ,
    Prop::ROLES_COUNT => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
    Prop::ROLES       => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::DEFAULT , Skin::FULL ] ] ,
    Prop::PERMISSIONS => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL ] ] ,
]
```

Result:

- `GET /users` (default skin `default`) returns `_key`, `email`, `rolesCount` and `roles[]`.
- `GET /users/{id}` (default skin `full`) returns `_key`, `email`, `roles[]` and `permissions[]` (the count drops).

A field without a `Field::SKINS` marker is always visible.

The marker accepts three shapes:

```php
Field::SKINS => [ Skin::FULL , Skin::DEFAULT ]   // array of skins
Field::SKINS => 'main,full'                       // comma-separated string
Field::SKINS => null                              // equivalent to no marker
```

Skins are opaque strings. Any skin defined in `Acme\enums\Skin` (which extends the `oihana\controllers\enums\traits\SkinTrait` trait) can be used freely, including business skins like `Skin::IMAGE`, `Skin::OFFERS`, `Skin::EMPLOYEE`.

## Per-request projection — `Field::SKINS` on sub-fields

When the projection of an edge varies only slightly between skins, the lightest path is to put `Field::SKINS` on the sub-fields of the projection. The request skin is propagated automatically to the target through `$init` (parent-skin inheritance) or can be pinned explicitly via `AQL::SKIN`.

Example: on `/users`, you want flat roles in the list and rich roles on the single fiche. Without duplicating the definition:

```php
// users.php
Prop::ROLES =>
[
    AQL::MODEL  => EdgesDefinition::USER_HAS_ROLES ,
    AQL::FIELDS =>
    [
        // Flat fields — visible in every skin (no marker)
        Prop::_KEY                        => Filter::DEFAULT ,
        Prop::NAME                        => Filter::DEFAULT ,
        Prop::IDENTIFIER                  => Filter::DEFAULT ,

        // Counts only on the list, hydrated relations only on the single fiche
        Prop::PERMISSIONS_COUNT           => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::PERMISSIONS                 => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
        Prop::APPLICATION_TEMPLATES_COUNT => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::APPLICATION_TEMPLATES       => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
    ] ,
    AQL::EDGES =>
    [
        Prop::PERMISSIONS_COUNT           => Prop::PERMISSIONS ,
        Prop::PERMISSIONS                 => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_PERMISSIONS ] ,
        Prop::APPLICATION_TEMPLATES_COUNT => Prop::APPLICATION_TEMPLATES ,
        Prop::APPLICATION_TEMPLATES       => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_APPLICATION_TEMPLATES ] ,
    ] ,
]
```

Outcomes:

- `GET /users` (skin `default`): each role exposes its flat fields, plus `permissionsCount`;
- `GET /users/{id}?skin=full` or `GET /me`: each role additionally exposes hydrated `permissions[]`.

The same definition covers both cases. Dedicated sub-endpoints (`/users/{id}/roles`, `/users/{id}/permissions/effective`) have their own DI and stay rich independently.

### `Field::SKINS` in depth — nested sub-fields (`Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP`)

The `Field::SKINS` marker is honored at **every nesting level** of a projection: on the sub-fields of a `Filter::MAP`, a `Filter::DOCUMENT` or a `Filter::WRAP` — a MAP inside a MAP included. The request skin is propagated to the nested `Field::FIELDS`, with the same rules as on the first level:

- a sub-field **without** a marker is visible in every skin;
- when no skin is requested, everything passes;
- a filtered sub-field disappears **entirely**: its key is absent from the response and, when it carries a relation marker (with its `Field::EDGES` / `Field::JOINS` entry at the same level), the matching `LET` is not emitted.

Example: a product stores a price grid `offers[]`, each entry containing a nested `offers[]` sub-array (one price per customer type). Each price carries a sensitive `priceSpecification` breakdown that must only appear in dedicated skins — with a single declaration of the field:

```php
'offers' =>
[
    Field::FILTER => Filter::MAP ,
    Field::FIELDS =>
    [
        'offers' =>
        [
            Field::FILTER => Filter::MAP ,
            Field::FIELDS =>
            [
                'price'              => Filter::DEFAULT ,
                'priceSpecification' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'offers.full' , 'full' ] ] ,
            ] ,
        ] ,
    ] ,
]
```

Outcomes:

- `GET /products/{id}` (skin `default`): the grid comes out with `price`, without `priceSpecification`;
- `GET /products/{id}?skin=full`: the `priceSpecification` breakdown appears on every price.

**Emptied parent = dropped parent.** When the skin removes **all** the declared sub-fields of a MAP / DOCUMENT / WRAP parent, the parent itself disappears from the projection (key absent) — never a raw sub-document fallback, never an empty object, never an error. This is the natural skin semantics: a field outside the skin does not appear.

**Cohabitation with `Field::REQUIRES`.** Both markers compose on the same sub-field: `Field::SKINS` decides the **view** (the requested skin), `Field::REQUIRES` the **security** (the permission). The sub-field only appears when the skin matches **and** the permission is granted.

## Alternative projection per skin — `AQL::SKIN_FIELDS`

When the projection differs broadly between skins and putting `Field::SKINS` everywhere would hurt readability, declare distinct projections via `AQL::SKIN_FIELDS`: a `skin => projection` table where each projection is a fields array of the **same shape as `AQL::FIELDS`**. When building the sub-query, the framework picks the bucket matching the request skin.

```php
AQL::EDGES =>
[
    Prop::ROLES =>
    [
        AQL::MODEL       => EdgesDefinition::USER_HAS_ROLES ,
        AQL::SKIN_FIELDS =>
        [
            // Flat version (skin `default`, the list): scalar fields only
            Skin::DEFAULT =>
            [
                Prop::_KEY => Filter::DEFAULT ,
                Prop::NAME => Filter::DEFAULT ,
            ] ,

            // Rich version (skin `full`, the single fiche): same fields + a hydrated relation
            Skin::FULL =>
            [
                Prop::_KEY        => Filter::DEFAULT ,
                Prop::NAME        => Filter::DEFAULT ,
                Prop::PERMISSIONS => Filter::EDGES ,
            ] ,

            // Optional: fallback bucket for any other skin
            '*' =>
            [
                Prop::_KEY => Filter::DEFAULT ,
                Prop::NAME => Filter::DEFAULT ,
            ] ,
        ] ,
        AQL::EDGES =>
        [
            Prop::PERMISSIONS => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_PERMISSIONS ] ,
        ] ,
    ] ,
]
```

Each bucket is a **complete, standalone** projection: the selected bucket fully replaces the others, there is **no merging** between buckets (hence the repeated `_key`/`name` — see the factoring below). The `Filter::EDGES` marker in the `full` bucket relies on the definition's `AQL::EDGES` entry, exactly like in a classic `AQL::FIELDS` projection; since the `default` bucket does not carry the marker, the sub-traversal is not emitted for that skin.

Internal resolution order:

1. `AQL::SKIN_FIELDS[$skin]` — explicit projection for the active skin;
2. `AQL::SKIN_FIELDS['*']` — fallback bucket within the table;
3. `AQL::FIELDS` — legacy single projection (backwards compatibility);
4. `null` — no projection declared.

If `AQL::SKIN_FIELDS` is absent or not an array, the resolution falls back directly on `AQL::FIELDS`, which guarantees backwards compatibility with pre-existing definitions.

`AQL::SKIN_FIELDS` is also recognised by `buildJoinVariable`; the mechanism is strictly the same for joins.

### Factoring the buckets with a projection function

Buckets often share a common base; writing it in every bucket is tedious and drift-prone (a field added in one bucket and forgotten in another). The usual pattern is a **projection function** on the host-project side: a plain helper returning the base and merging extras into it.

```php
/**
 * Base projection of a role; $extra adds (or overrides) fields per bucket.
 */
function role( array $extra = [] ) :array
{
    return
    [
        Prop::_KEY => Filter::DEFAULT ,
        Prop::NAME => Filter::DEFAULT ,
        ...$extra ,
    ] ;
}
```

The table from the previous example becomes compact:

```php
AQL::SKIN_FIELDS =>
[
    Skin::DEFAULT => role() ,                                       // flat version: the base alone
    Skin::FULL    => role([ Prop::PERMISSIONS => Filter::EDGES ]) , // base + hydrated relation
    '*'           => role() ,                                       // optional: fallback
] ,
```

This helper belongs to the **host project** — it does not exist in the library; it is a configuration convention, not an API. It pays off as soon as several buckets (or several edge/join definitions targeting the same model) share the same field base.

### At the model level — one projection per skin for the root

**The situation.** The `GET /products` list must stay light (two fields are enough for a grid display); the `GET /products/{id}?skin=full` record must return everything. With `Field::SKINS` markers alone, every field would need its own annotation — unreadable as soon as the two projections really diverge. The same `skin => projection` table is accepted **at the model root**, beside (or instead of) `AQL::FIELDS`:

```php
Models::PRODUCTS => fn( Container $c ) => new Documents( $c ,
[
    AQL::COLLECTION  => 'products' ,
    AQL::SKIN_FIELDS =>
    [
        // The list: two fields, nothing else
        Skin::DEFAULT =>
        [
            Prop::_KEY => Filter::DEFAULT ,
            Prop::NAME => Filter::DEFAULT ,
        ] ,

        // The record: the same + the description and the price grid
        Skin::FULL =>
        [
            Prop::_KEY        => Filter::DEFAULT ,
            Prop::NAME        => Filter::DEFAULT ,
            Prop::DESCRIPTION => Filter::TRANSLATE ,
            'offers'          => [ Field::FILTER => Filter::MAP , Field::FIELDS => [ 'price' => Filter::DEFAULT ] ] ,
        ] ,
    ] ,
    // AQL::FIELDS is still possible beside it: single projection, used when no bucket matches
])
```

`GET /products` returns `{ _key, name }` per product; `GET /products/{id}?skin=full` returns the full record. The resolution order is **the same as for edges/joins**: `[$skin]` → `['*']` → `AQL::FIELDS` → nothing. Two behaviors worth knowing:

- **an empty bucket** (`Skin::X => []`) reads "no projection for this skin" → the **whole** document is returned, exactly like an edge target without a projection;
- **without a registry**, nothing changes: `AQL::FIELDS` alone behaves as before, byte for byte.

**Inheritance through the relations.** An edge or join that declares **no** projection of its own prepares the target model's fields with the request skin — so it automatically inherits the model's buckets. Concretely: if the `roles` model declares its two projections in its own `AQL::SKIN_FIELDS`, every `user_has_roles` edge pointing at it returns the current skin's version **without declaring anything** on the edge definition. One declaration, on the model side, radiates everywhere.

### On a structural sub-field — two shapes for the same key

**The situation.** Back to the price grid. The nested [`Field::SKINS` marker](#fieldskins-in-depth--nested-sub-fields-filtermap--filterdocument--filterwrap) can **show or hide** a sub-field per skin — but it cannot give **two different shapes to the same key** (a key is unique in a map, you cannot declare two `offers`). The per-skin table can, declared on the `Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP` sub-field itself:

```php
'offers' =>
[
    Field::FILTER    => Filter::MAP ,
    AQL::SKIN_FIELDS =>
    [
        // Public shape: the price, nothing else
        Skin::DEFAULT => [ 'price' => Filter::DEFAULT ] ,

        // Manager shape: the price + its breakdown
        Skin::FULL    =>
        [
            'price'              => Filter::DEFAULT ,
            'priceSpecification' => [ Field::FILTER => Filter::DOCUMENT , Field::FIELDS => [ 'basePrice' => Filter::DEFAULT , 'taxes' => Filter::DEFAULT ] ] ,
        ] ,
    ] ,
]
```

The public receives `offers: [ { "price": 100 }, … ]`; the `full` skin additionally receives the breakdown, **structured differently** — the same `offers` key, two shapes. Everything composes in the usual order: the bucket is picked by the skin, then the `Field::SKINS` markers filter *inside* the bucket, then the `REQUIRES` locks apply on what remains.

**The "nothing for this skin" rule.** When the declared table resolves to nothing for the requested skin — no bucket under its name, no `'*'`, no `Field::FIELDS` beside it, or an explicitly empty bucket — the sub-field **disappears** from the projection (key absent). The declaration reads "nothing is planned for this skin". Never a raw sub-document fallback (which would leak precisely what was meant to be hidden), never an exception. Example: the table above without its `Skin::DEFAULT` bucket → on the `default` skin, the `offers` key simply does not appear.

### Scope of `AQL::SKIN_FIELDS`

Two points worth knowing:

- The key is read at **three levels**: the edge/join definitions (re-resolved at every relation nesting level), the **model root**, and the **structural sub-fields** (`Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP`). Placed anywhere else — on a scalar field for instance — it is silently ignored; to show/hide a field per skin, the mechanism remains [`Field::SKINS`](#per-request-projection--fieldskins-on-sub-fields).
- A skin pinned via `AQL::SKIN` only applies to the definition carrying it: its nested relations (nested `AQL::EDGES` / `AQL::JOINS`) fall back on the request skin, unless explicitly pinned on their own definition.

## Which mechanism to use?

| Need | Recommended solution |
|---|---|
| A single projection regardless of the skin | `AQL::FIELDS` alone |
| A few sub-fields vary between skins (count hidden on full, edge hidden on default…) | `Field::SKINS` on the sub-fields of `AQL::FIELDS` |
| A **nested** sub-field (price grid, sub-object of a MAP/DOCUMENT/WRAP) must only appear in some skins | `Field::SKINS` on the nested sub-field — honored at every depth |
| The projection differs broadly between skins (added fields, swapped joins…) | `AQL::SKIN_FIELDS` with one entry per skin — on the edge/join definition, the model root, or a MAP/DOCUMENT/WRAP sub-field |
| The same nested key needs **two shapes** per skin (minimal grid vs broken-down) | `AQL::SKIN_FIELDS` on the structural sub-field |
| INBOUND edge towards a document that may reference back to the source | `AQL::SKIN => Skin::MAIN` on the edge definition to break the cycle |
| Restrict an edge or join projection to a user permission | `AQL::REQUIRES` on the definition — the callable is posed automatically by the base (or `InjectAuthorizerTrait` for a stable callable) |

The mechanisms compose. A definition can combine `AQL::SKIN_FIELDS` for the main projection, `Field::SKINS` on the sub-fields of each individual projection, and an `AQL::SKIN` to pin the target skin. The resolution is independent at each level.

## Permission-gated edges and joins — `AQL::REQUIRES`

A relation can be permission-locked at **two composable levels**:

- `Field::REQUIRES` on **the field** of a projection: locks *that particular projection* of the relation;
- `AQL::REQUIRES` on **the definition** of the edge or join: locks the relation *wherever the definition is used* — declared once, enforced everywhere.

In both cases a denied relation is silently omitted: no `LET` is emitted, the key never appears in the response (no `null`, no empty array), no error. The mechanism stays agnostic of the underlying authorization layer: the decision is delegated to a callable injected through `$init[Arango::AUTHORIZER]` (see the wiring below).

### The setting for the examples

A company API. A `users` collection (the employee records), a `roles` collection (the application roles), linked by `user_has_roles` edges. Two people call **the same route** `GET /users/123`:

- **Alice**, an administrator: she holds the `users.roles:list` permission;
- **Bob**, a regular employee: he does not.

Goal: Alice sees the roles on the record, Bob sees the same record **without** the roles — no error, no empty field, no second route.

### Locking the relation on its definition

**The situation.** To hide the roles from Bob with the field-level lock alone, you would have to declare `Field::REQUIRES` on the `roles` field of **every projection** that mentions it. If three models or three screens project this relation, that is three locks to remember — forgetting one is a leak. The definition-level lock is declared **once, on the definition of the relation itself**: no matter who projects it, where and how, it is protected.

```php
Models::USERS => fn( Container $c ) => new Documents( $c ,
[
    AQL::COLLECTION => 'users' ,
    AQL::FIELDS =>
    [
        Prop::_KEY  => Filter::DEFAULT ,
        Prop::NAME  => Filter::DEFAULT ,
        Prop::ROLES => [ Field::FILTER => Filter::EDGES ] ,   // the relation is projected, no lock here
    ] ,
    AQL::EDGES =>
    [
        Prop::ROLES =>
        [
            AQL::MODEL    => EdgesDefinition::USER_HAS_ROLES ,
            AQL::REQUIRES => 'users.roles:list' ,             // ← THE lock, declared once and for all
        ] ,
    ] ,
])
```

**What each of them receives** on `GET /users/123`:

```jsonc
// Alice (permission granted)                  // Bob (permission denied)
{                                              {
  "_key" : "123" ,                               "_key" : "123" ,
  "name" : "Jeanne Martin" ,                     "name" : "Jeanne Martin"
  "roles": [ { "name": "manager" } ]             // no "roles" key at all
}                                              }
```

For Bob, the query sent to ArangoDB does not even contain the roles traversal any more: what will not be shown is not computed.

### The "whole document" route

**The situation.** Some routes define no field list at all: the framework then returns the complete document, enriched with every relation declared on the model. The definition-level lock applies on that path too: Bob receives the full document **minus** the relations he is not allowed to see. Nothing extra to declare — it is the same declaration as the previous example. (An **alias** entry of the registry — `'members' => 'roles'` — follows its target's authorization: if `roles` is denied, `members` disappears too.)

### Two locks composing

**The situation.** HR asks: "roles are only visible to managers (`users.roles:list`), and on the full HR screen you must **additionally** be HR-cleared (`rh:read`)". Two requirements at two different levels: one on the relation itself, one on a specific screen. Each is declared at its own level, and **both must be satisfied**:

```php
AQL::FIELDS =>
[
    Prop::ROLES =>
    [
        Field::FILTER   => Filter::EDGES ,
        Field::REQUIRES => 'rh:read' ,            // lock of THIS projection (the HR screen)
    ] ,
] ,
AQL::EDGES =>
[
    Prop::ROLES =>
    [
        AQL::MODEL    => EdgesDefinition::USER_HAS_ROLES ,
        AQL::REQUIRES => 'users.roles:list' ,     // lock of the relation, everywhere
    ] ,
]
```

A manager without HR clearance does not see the roles on the HR screen; an HR-cleared user who is not a manager does not either. Conversely, **inside a single lock**, a list of permissions reads as an OR: `AQL::REQUIRES => [ 'users.roles:list' , 'users.roles:admin' ]` = "either one is enough".

### A relation buried in a sub-array

**The situation.** A product record carries an `offers` array (one entry per price offer). Each offer is linked to its sellers through an edge. The public browses the catalog and sees the prices; only the catalog managers (`offers.sellers:list`) see **who sells**. The relation is buried inside a sub-array here — the lock works exactly the same:

```php
'offers' =>
[
    Field::FILTER => Filter::MAP ,                    // iterate the offers array
    Field::FIELDS =>
    [
        'price'   => Filter::DEFAULT ,
        'sellers' => [ Field::FILTER => Filter::EDGES ] ,
    ] ,
    Field::EDGES =>
    [
        'sellers' => [ AQL::MODEL => OfferHasSellers::class , AQL::REQUIRES => 'offers.sellers:list' ] ,
    ] ,
]
```

The public receives `offers: [ { "price": 100 }, … ]`; the manager additionally receives `"sellers": [...]` inside each offer. The same holds when the relation is buried in a sub-object (`Filter::DOCUMENT`), a wrapped object (`Filter::WRAP`), or at the end of a cascade (the relation of a relation): the lock is checked **at every level**.

### Accepted shapes

`AQL::REQUIRES` (like `Field::REQUIRES`) accepts two shapes:

- **A string** — a single required permission subject.
- **An array of strings** — OR semantics: the projection is allowed as soon as **at least one** of the subjects is granted.

When the key is absent, no check is performed — default behaviour, no risk for existing definitions.

### Limits of the mechanism

**Limit 1 — Code that builds AQL by hand checks by itself.** In normal use (the models, `list()`, `get()`, the controllers) the check is automatic. But the library also exposes the low-level functions that build an isolated query fragment — `buildEdgeVariable()` for instance. Called **directly** with a locked definition, they build the fragment without asking: at that level the caller is assumed to know what it is doing. As long as a project goes through the models, this limit does not concern it.

**Limit 2 — Search has its own locks, separate and untouched.** Full-text search (`?search=`, the Views — `Search::REQUIRES` on the specs) and the federated multi-collection search each have their own permission system. An `AQL::REQUIRES` declared on an edge definition does not protect a search result: each layer has its own lock.

**Limit 3 — The stored-array counter has no definition to lock.** `Filter::JOINS_COUNT` follows no relation — it counts the elements of an array **already stored in the document** (e.g. `doc.memberIds`). There is no definition behind it, hence nowhere to declare `AQL::REQUIRES`: to hide it, declare `Field::REQUIRES` on the field itself.

**Limit 4 — Without an injected authorizer, everything is open.** If no authorizer is posed (an admin script, an internal job, a test, or simply an authorization stack not registered in the container), no lock blocks anything: everything comes out. That is the existing contract (see "Behaviour when no authorizer is present" below) — the protection only exists where the authorization stack is registered and the request authenticated (the base then poses the callable automatically).

### Wiring on the controller side — automatic from the base

`oihana/php-arango` knows nothing of the authorization layer in use (Casbin, OPA, custom, ...): the decision is delegated to a `Closure(string $subject): bool`. **As of the current version, `DocumentsController` provides that callable on its own — you no longer override anything.**

The base composes the `PermissionAuthorizerTrait` trait and, right in its constructor (via [`AuthorizationContextTrait`](../../src/oihana/arango/controllers/traits/AuthorizationContextTrait.php)), arms the capability enforcer and the subject resolver read from the DI container (`CapabilityEnforcerInterface` and `PermissionSubjectResolverInterface`). It overrides the `beforeModelCall( ?Request , array &$init )` hook itself — from [`ModelCallTrait`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/traits/ModelCallTrait.php), automatically invoked around every primary CRUD operation (`list`, `get`, `last`, `count`, `insert`, `update`, `replace`, `delete`) — to build a request-scoped authorizer (`buildPermissionAuthorizer()`) on each request and pose it under `Arango::AUTHORIZER`.

In practice: **just register `CapabilityEnforcerInterface` and `PermissionSubjectResolverInterface` in the DI container.** As soon as they are present and the request carries an authenticated user, the `Field::REQUIRES` / `AQL::REQUIRES` locks apply on **every** verb of the controller — with no per-controller, no per-HTTP-verb wiring line.

Two guards preserve compatibility:

- **an authorizer already set in `$init` wins** — a caller, a unit test, or a subclass that set one earlier is never overwritten ;
- **`buildPermissionAuthorizer()` returns `null` — hence nothing is posed — when a piece is missing** (no request, no enforcer, no resolver, or no authenticated user). The framework then falls back on its default behaviour (fail open, see next section), so a controller that does not carry the authorization stack keeps its previous behaviour.

> ⚠ **Backward compatibility.** Wherever the Casbin stack and an authenticated user are present, a `Field::REQUIRES` / `AQL::REQUIRES` marker that was so far dormant now becomes **enforced**. Audit your model definitions for such markers before updating.

A subclass now overrides `beforeModelCall` only for a special case: to provide an authorizer computed differently, or to pose a stable callable not bound to the request (see the variant below).

### Variant — request-agnostic pattern with `InjectAuthorizerTrait`

When the callable is known at controller construction time (unit test, callable resolved straight from the DI container without depending on the request, CLI batch mode, ...), an alternative trait [`InjectAuthorizerTrait`](../../src/oihana/arango/controllers/traits/inject/InjectAuthorizerTrait.php) (on the `oihana/php-arango` side, agnostic of Casbin) lets a controller store a stable callable at construction and pose it on every `$init`:

```php
use oihana\arango\controllers\traits\inject\InjectAuthorizerTrait;

final class BatchController extends DocumentsController
{
    use InjectAuthorizerTrait ;

    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;
        $this->initializeArangoAuthorizer( $init , fn() : bool => true ) ;
    }

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;
        $this->injectAuthorizer( $init ) ;
    }
}
```

`initializeArangoAuthorizer` accepts any standard PHP callable shape (Closure, invokable, `[obj, 'method']`, `'Class::method'`, fully-qualified function name — resolution goes through `oihana\core\callables\resolveCallable`). For Casbin + request-scoped use cases in production, nothing to do: the base's automatic wiring (previous section) is enough.

### Behaviour when no authorizer is present

If `$init[Arango::AUTHORIZER]` is not set (no request, the enforcer or the subject resolver is not registered in the container, or the request carries no authenticated user — the conditions under which `buildPermissionAuthorizer()` returns `null`), the internal `isAuthorized` helper returns `true` by default — the projection is **allowed** (fail open). This avoids breaking a route when `AQL::REQUIRES` is added on a shared definition before the authorization stack is in place everywhere.

For strict gating, the `Authorized` middleware on the HTTP route (Casbin HTTP-permission level) must remain the primary envelope — `AQL::REQUIRES` is a **second layer** of access control inside the AQL projection, not a replacement.

### Internal helpers — `isAuthorized` and `authorizeRelationFields`

`isAuthorized($definition, $init)` is the single judge for both lock levels: `buildVariables` calls it on the field entry **and** on the definition when deciding to emit each `LET`; `aqlFields` calls it on each field at projection time; `buildEdgesVariables`/`buildJoinVariables` call it on each definition of the whole-document route. Its signature and behaviour:

```php
function isAuthorized( array $definition , array $init = [] ) : bool
```

- No `AQL::REQUIRES` → `true` (no-op).
- No callable under `Arango::AUTHORIZER`, or non-callable value → `true` (fail open).
- A string or array → `true` as soon as **at least one** subject is granted by the callable. Only strict `true` counts as a grant (a truthy `1`, `'yes'`, etc. does not allow the projection).

The helper lives at `oihana\arango\models\helpers\isAuthorized`.

Its companion `authorizeRelationFields($fields, $edges, $joins, $init)` (same namespace) keeps the definition-level lock **symmetric**: a relation is emitted by two parallel paths — the `LET` sub-query on one side, the projected key in the `RETURN` on the other. When a definition is denied, this helper removes the matching field from the projection, so the `RETURN` never references a variable that was not emitted. It is applied automatically wherever a projection meets its edges/joins registries — you never call it yourself.

## Transforming the projected value — `Field::ALTERS`

`Field::ALTERS` applies an **AQL transformation chain** to a field's value at **`RETURN` time**, exactly like the filters' [`alt`](db/filter.md#alt-transformations) transformations — but on the **output** side. It is the projection counterpart: what `alt` does to compare (`LOWER(doc.x) == LOWER(@v)`), `ALTERS` does to return (`name: LOWER(doc.name)`).

The chain reuses the same vocabulary as `alt` (the `FilterFunction` registry):

- a **single function**: `'lower'` → `LOWER(doc.x)`;
- a **function chain**: `['trim','lower']` → `LOWER(TRIM(doc.x))` (applied left to right, the last one wraps);
- a **function with parameters**: `['substring', 0, 3]` → `SUBSTRING(doc.x, 0, 3)`;
- a **mixed chain**: bare functions and parameterized functions can be combined in the same list — `['trim', ['substring',0,3], 'lower']` → `LOWER(SUBSTRING(TRIM(doc.x), 0, 3))`.

### Declaration

```php
Arango::FIELDS =>
[
    // name returned normalized: trimmed and lower-cased
    'name'  => [ Field::ALTERS => [ 'trim' , 'lower' ] ] ,

    // an output alias (slug) computed from another field (title)
    'slug'  => [ Field::NAME => 'title' , Field::ALTERS => 'lower' ] ,

    // a code truncated to the first 3 characters
    'code'  => [ Field::NAME => 'reference' , Field::ALTERS => [ 'substring' , 0 , 3 ] ] ,
] ,
```

Produces the projection:

```aql
RETURN {
    name : LOWER(TRIM(doc.name)) ,
    slug : LOWER(doc.title) ,
    code : SUBSTRING(doc.reference, 0, 3)
}
```

### Worked examples

| Intent | Declaration | Projected AQL |
|---|---|---|
| Email normalized to lower case | `'email' => [ Field::ALTERS => 'lower' ]` | `email: LOWER(doc.email)` |
| Trimmed title | `'title' => [ Field::ALTERS => 'trim' ]` | `title: TRIM(doc.title)` |
| Lower-case slug from `title` | `'slug' => [ Field::NAME => 'title', Field::ALTERS => 'lower' ]` | `slug: LOWER(doc.title)` |
| Cleaned proper name | `'name' => [ Field::ALTERS => ['trim','lower'] ]` | `name: LOWER(TRIM(doc.name))` |
| Initials (3 chars) | `'code' => [ Field::ALTERS => ['substring',0,3] ]` | `code: SUBSTRING(doc.code,0,3)` |

On the data `{ name: "  Jean DUPONT  ", title: "Hello World" }`, the projection above returns `{ name: "jean dupont", slug: "hello world" }`.

### Scope and rules

- **Opt-in per field**: a field without `Field::ALTERS` is projected unchanged (no change to existing behaviour).
- **Default scalar projection only** (`key: doc.key`). On a field carrying a **typed `Field::FILTER`** (`BOOL`, `DATETIME`, `NUMBER`…) or a **structural** one (`EDGE`, `JOIN`, `MAP`, `DOCUMENT`…), `Field::ALTERS` is **ignored**: a scalar chain (`LOWER`, `TRIM`…) makes no sense on a sub-object or a type conversion. Use one **or** the other.
- **`Field::NAME`** selects the source attribute; the output key stays the one from the definition (handy to expose a transformed field under another name, e.g. `slug`).
- No injection risk: function names are **whitelisted** (`FilterFunction`) — an unknown function is a no-op.

## Internal reference — the `matchesSkin` helper

`matchesSkin($skins, $currentSkin)` is used internally by `FieldsTrait::filterFieldsBySkin` to evaluate the `Field::SKINS` markers. It is **not** part of the public API of the projection framework — you don't need to call it directly.

Its signature and behaviour, for reference:

```php
function matchesSkin( mixed $skins , ?string $currentSkin ) :bool
```

- `null` for either argument: always returns `true` (no filter).
- Array: `in_array($currentSkin, $skins, true)`.
- String: equivalent to a comma-separated array, whitespace-tolerant.
- Any other shape: returns `true` by default (defensive default for malformed definitions).

The helper lives at `oihana\arango\db\helpers\matchesSkin`.
