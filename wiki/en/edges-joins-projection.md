# AQL edge and join projection

## Table of contents

1. [Overview](#overview)
2. [The `Field::SKINS` marker at the document level](#the-fieldskins-marker-at-the-document-level)
3. [Composed projection — `AQL::FIELDS` + `AQL::EDGES` on the edge definition](#composed-projection--aqlfields--aqledges-on-the-edge-definition)
4. [Breaking an INBOUND cycle with `AQL::SKIN`](#breaking-an-inbound-cycle-with-aqlskin)
5. [Per-request projection — `Field::SKINS` on sub-fields](#per-request-projection--fieldskins-on-sub-fields)
6. [Alternative projection per skin — `AQL::SKIN_FIELDS`](#alternative-projection-per-skin--aqlskin_fields)
7. [Which mechanism to use?](#which-mechanism-to-use)
8. [Permission-gated edges and joins — `AQL::REQUIRES`](#permission-gated-edges-and-joins--aqlrequires)
9. [Internal reference — the `matchesSkin` helper](#internal-reference--the-matchesskin-helper)

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

## Composed projection — `AQL::FIELDS` + `AQL::EDGES` on the edge definition

When an edge points to a complex document, declare its projection by composing `AQL::FIELDS` and `AQL::EDGES` directly on the edge definition inside `AQL::EDGES`. The pattern is illustrated by `employeeEdge.php`:

```php
// Host-project example (`Acme\functions\edges\employeeEdge`).
function employeeEdge(
    ?string $employeePath     = Paths::PEOPLE ,
    ?string $workLocationPath = Paths::LOCATIONS ,
) :array
{
    return
    [
        AQL::MODEL  => EdgesDefinition::CUSTOMER_HAS_EMPLOYEE ,
        AQL::SORT   => Prop::POSITION ,
        AQL::FIELDS => person
        ([
            Prop::ID            => Filter::DEFAULT ,
            Prop::ACTIVE        => Filter::DEFAULT ,
            Prop::ADDRESS       => Filter::DEFAULT ,
            Prop::FAMILY_NAME   => Filter::DEFAULT ,
            Prop::GIVEN_NAME    => Filter::DEFAULT ,
            Prop::WORK_LOCATION => Filter::EDGE ,    // sub-edge declared below
        ] , $employeePath ) ,
        AQL::EDGES =>
        [
            Prop::WORK_LOCATION => workLocationEdge( $workLocationPath ) ,
        ] ,
    ] ;
}
```

And on the consuming DI side:

```php
// customers.php
AQL::EDGES =>
[
    Prop::EMPLOYEE => employeeEdge() ,
    Prop::LOCATION => locationEdge() ,
]
```

Important points:

- `AQL::FIELDS` on the edge definition **is read** by `buildEdgeVariable`. This is the effective projection used to hydrate the target document.
- `AQL::EDGES` on the edge definition declares the sub-edges referenced by `Filter::EDGE` or `Filter::EDGES` markers in the projection.
- `Field::FIELDS` placed **inline at the parent field level** is ignored for `Filter::EDGES` (it's only honoured for `Filter::DOCUMENT` and `Filter::MAP`). A common pitfall: declare the projection at the right level (on the edge definition, not on the parent field).

## Breaking an INBOUND cycle with `AQL::SKIN`

INBOUND edges towards a document that points back to the source create a potentially infinite hydration cycle. Example: on a `Policy`, you want to expose INBOUND the list of `Service` that reference it. But a `Service` has `Policy` OUTBOUND, and each `Policy` projects its `Service` again, and so on.

The fix is `AQL::SKIN => Skin::MAIN` on the edge definition. The `Skin::MAIN` mode filters the target projection to keep only fields without a `Field::SKINS` marker — so sub-edges (all gated behind `Skin::FULL` or `Skin::DEFAULT`) are dropped and the cycle stops.

```php
// policies.php — reverse exposure of services
AQL::EDGES =>
[
    Prop::SERVICES_COUNT => Prop::SERVICES ,
    Prop::SERVICES       =>
    [
        AQL::MODEL     => EdgesDefinition::SERVICE_HAS_POLICIES ,
        AQL::DIRECTION => Traversal::INBOUND ,
        AQL::SKIN      => Skin::MAIN ,             // breaks the cycle
    ] ,
]
```

Without `AQL::SKIN => Skin::MAIN`, Xdebug aborts the request with a 500 error "infinite loop, aborted your script with a stack depth of '512' frames" on **every route** (the DI container compiles the `Documents` models when each Slim request boots). The symptom is misleading: it isn't the route that loops, it's the definition.

## Per-request projection — `Field::SKINS` on sub-fields

When the projection of an edge varies only slightly between skins, the lightest path is to put `Field::SKINS` on the sub-fields of the projection. The request skin is propagated automatically to the target through `$init` (parent-skin inheritance) or can be pinned explicitly via `AQL::SKIN`.

Example: on `/users`, you want flat roles in the list and rich roles on the single fiche. Without duplicating the definition:

```php
// users.php
Prop::ROLES =>
[
    AQL::MODEL  => EdgesDefinition::USER_HAS_ROLES ,
    AQL::FIELDS => role
    ([
        Prop::IDENTIFIER                  => Filter::DEFAULT ,
        Prop::PERMISSIONS_COUNT           => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::PERMISSIONS                 => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
        Prop::APPLICATION_TEMPLATES_COUNT => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::APPLICATION_TEMPLATES       => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
    ]) ,
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

## Alternative projection per skin — `AQL::SKIN_FIELDS`

When the projection differs broadly between skins and putting `Field::SKINS` everywhere would hurt readability, declare distinct projections via `AQL::SKIN_FIELDS`.

General shape:

```php
AQL::EDGES =>
[
    Prop::ROLES =>
    [
        AQL::MODEL       => EdgesDefinition::USER_HAS_ROLES ,
        AQL::SKIN_FIELDS =>
        [
            Skin::DEFAULT => role() ,                                       // flat version
            Skin::FULL    => role([ Prop::PERMISSIONS => Filter::EDGES ]) , // rich version
            '*'           => role() ,                                        // optional fallback bucket
        ] ,
        AQL::EDGES =>
        [
            Prop::PERMISSIONS => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_PERMISSIONS ] ,
        ] ,
    ] ,
]
```

Internal resolution order:

1. `AQL::SKIN_FIELDS[$skin]` — explicit projection for the active skin;
2. `AQL::SKIN_FIELDS['*']` — fallback bucket within the table;
3. `AQL::FIELDS` — legacy single projection (backwards compatibility);
4. `null` — no projection declared.

If `AQL::SKIN_FIELDS` is absent or not an array, the resolution falls back directly on `AQL::FIELDS`, which guarantees backwards compatibility with pre-existing definitions.

`AQL::SKIN_FIELDS` is also recognised by `buildJoinVariable`; the mechanism is strictly the same for joins.

## Which mechanism to use?

| Need | Recommended solution |
|---|---|
| A single projection regardless of the skin | `AQL::FIELDS` alone |
| A few sub-fields vary between skins (count hidden on full, edge hidden on default…) | `Field::SKINS` on the sub-fields of `AQL::FIELDS` |
| The projection differs broadly between skins (added fields, swapped joins…) | `AQL::SKIN_FIELDS` with one entry per skin |
| INBOUND edge towards a document that may reference back to the source | `AQL::SKIN => Skin::MAIN` on the edge definition to break the cycle |
| Restrict an edge or join projection to a user permission | `AQL::REQUIRES` on the definition + callable injection via `InjectAuthorizerTrait` |

The mechanisms compose. A definition can combine `AQL::SKIN_FIELDS` for the main projection, `Field::SKINS` on the sub-fields of each individual projection, and an `AQL::SKIN` to pin the target skin. The resolution is independent at each level.

## Permission-gated edges and joins — `AQL::REQUIRES`

A definition can declare a required permission via `AQL::REQUIRES`. When the current user does not hold that permission, the edge or join is silently dropped from the projection (no `LET` is emitted, no leak, no error). The mechanism stays agnostic of the underlying authorization layer: the decision is delegated to a callable injected through `$init[Arango::AUTHORIZER]`.

### Declaration shape

```php
Prop::ROLES =>
[
    AQL::MODEL    => EdgesDefinition::USER_HAS_ROLES ,
    AQL::REQUIRES => 'users.roles:list' ,
] ,
```

`AQL::REQUIRES` accepts two shapes:

- **A string** — a single required permission subject.
- **An array of strings** — OR semantics: the projection is allowed as soon as **at least one** of the subjects is granted. Useful when several permissions can open the same edge (for instance `users.roles:list` or `users.roles:admin`).

When `AQL::REQUIRES` is absent, no check is performed — default behaviour, no risk for existing definitions.

### Wiring on the controller side — recommended pattern

`oihana/php-arango` knows nothing of the authorization layer in use (Casbin, OPA, custom, ...). The controller provides a `Closure(string $subject): bool` that the framework will call for every declared subject.

`DocumentsController` exposes two lifecycle hooks from [`ModelCallTrait`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/traits/ModelCallTrait.php) — `beforeModelCall( ?Request , array &$init )` and `afterModelCall( ?Request , array &$init , mixed &$result )` — automatically invoked around every primary CRUD operation (`list`, `get`, `last`, `count`, `insert`, `update`, `replace`, `delete`). The recommended pattern is to override `beforeModelCall` once to enable access control on every HTTP verb of the controller:

```php
use oihana\api\controllers\traits\CapabilityAuthorizerTrait;
use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;

use Psr\Http\Message\ServerRequestInterface as Request;

final class UsersController extends DocumentsController
{
    use CapabilityAuthorizerTrait ;

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;

        if ( ( $authorizer = $this->buildAuthorizer( $request ) ) !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }
    }
}
```

`CapabilityAuthorizerTrait` — bundled in the `CapabilityGuardTrait` facade — builds a request-scoped `Closure(string): bool` against the Casbin `CapabilityEnforcer` and the current Zitadel `userId`. It applies `safeSubject` automatically (see [auth code tips](https://github.com/BcommeBois/oihana-php-auth/blob/main/wiki/en/tips.md)). When the enforcer is unavailable or the request carries no authenticated user, `buildAuthorizer` returns `null` — the `if` short-circuits and the framework falls back on its default behaviour (fail open, see next section).

Benefit: the override is **a single line per controller**, not per HTTP verb. The wiring covers `list`, `get`, `last`, `count`, `insert`, `update`, `replace`, `delete` automatically.

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

`initializeArangoAuthorizer` accepts any standard PHP callable shape (Closure, invokable, `[obj, 'method']`, `'Class::method'`, fully-qualified function name — resolution goes through `oihana\core\callables\resolveCallable`). For Casbin + request-scoped use cases in production, prefer the `CapabilityAuthorizerTrait` pattern above.

### Behaviour when no authorizer is present

If `$init[Arango::AUTHORIZER]` is not set (the controller does not override `beforeModelCall`, or no enforcer is registered for that controller), the internal `isAuthorized` helper returns `true` by default — the projection is **allowed** (fail open). This avoids breaking a route when `AQL::REQUIRES` is added on a shared definition before every consuming controller has been wired up.

For strict gating, the `Authorized` middleware on the HTTP route (Casbin HTTP-permission level) must remain the primary envelope — `AQL::REQUIRES` is a **second layer** of access control inside the AQL projection, not a replacement.

### Internal helper — `isAuthorized`

`isAuthorized($definition, $init)` is used by `buildVariables` to decide whether to include each edge or join. Its signature and behaviour:

```php
function isAuthorized( array $definition , array $init = [] ) : bool
```

- No `AQL::REQUIRES` → `true` (no-op).
- No callable under `Arango::AUTHORIZER`, or non-callable value → `true` (fail open).
- A string or array → `true` as soon as **at least one** subject is granted by the callable. Only strict `true` counts as a grant (a truthy `1`, `'yes'`, etc. does not allow the projection).

The helper lives at `oihana\arango\models\helpers\isAuthorized`.

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
