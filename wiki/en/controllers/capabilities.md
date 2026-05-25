# Capabilities

## What is a *capability*?

A *capability* is a **fine-grained permission** that applies to the **value of a parameter** or a **field**, not to the HTTP verb. Where a classic Casbin permission answers *"can this user do `GET /products`?"*, a *capability* answers more pointed questions:

- *"can this user request `?skin=offers.full` on `/products`?"*;
- *"can this user filter on `?filter=offers.priceBuying:>=100`?"*;
- *"can this user send the `manualPriceOverride` field in the body of a `PATCH /offers/{id}`?"*;
- *"can this user trigger the cross-cutting action `?bench=true`?"*.

In every case, the HTTP verb is **already authorized** by Casbin — the client does have the right to call the route. The *capability* finely gates what they can send **inside** the request.

Server-side, every *capability* is tied to a Casbin permission (typically `products:skin.offers.full`). If the user does not have that permission, the framework applies the configured policy: reject the request with 403, silently ignore the value, fall back on a default value, etc.

## Why a system separate from Casbin

Casbin is excellent at gating **endpoints**: `GET`, `POST`, `PATCH`, `DELETE` per resource. But Casbin cannot answer *"can this user pass this value in this parameter?"* — it only sees the verb and the route path.

Without a *capability* system, you have two mediocre solutions:

1. **Allow everything to everyone** — the controller does its best, and too bad if a low-hierarchy user can fetch buying prices normally reserved to the superadmin via `?skin=offers.full`.
2. **Multiply endpoints** — `/products/public`, `/products/admin-with-prices`, `/products/internal` — already seen, already rejected in [Skins](skins.md#why-a-skin-system).

The framework's *capability* system solves the problem by **keeping Casbin for verbs** and adding a gating layer on parameter values and field keys. It's all declarative (a single block in the controller's DI definition) and orthogonal to the `payload → rules → model` chain.

## What this page covers

This page documents:

- The **`Arango::CAPABILITIES` key** in the controller's DI definition, its format and sub-keys (`Capability::OBJECT`, `Capability::VALUES`, `Capability::KEYS`, `Capability::FALLBACK`, `Capability::POLICY`, `Capability::REQUIRE`, `Capability::DENY`).
- The **6 + 1 traits** that implement *capability* enforcement: `CapabilityGuardTrait` (facade) + `CapabilityContextTrait` (shared state) + `CapabilityParamTrait`, `CapabilityFilterKeysTrait`, `CapabilityBinaryTrait`, `CapabilityFieldsTrait`, `CapabilityAuthorizerTrait`.
- The **authorizer** pattern: a `Closure(string $subject): bool` injected per request that consults the Casbin `CapabilityEnforcer`.
- **Field-level gating** on the model side via `AQL::REQUIRES` (and its interaction with `Arango::AUTHORIZER`) — covered in detail in [Edge and join projection](../edges-joins-projection.md).

## Position in the pipeline

```
HTTP request
  → Authorized middleware (Casbin)               gate verb + path
  → PrepareSkin                                  set $init[ Arango::SKIN ]
  → enforceParam(?skin=)                         validate the skin VALUE (this page)
  → enforceFilterKeys(?filter=...)               validate the filter KEYS (this page)
  → enforceFields($payload)                      validate the body FIELDS (this page)
  → hasCapability(?bench=true)                   gate a cross-cutting action (this page)
  → preparePayload + validator                   cf. payloads.md + rules.md
  → buildAuthorizer($request) → $init[ AUTHORIZER ]
  → beforeModelCall($request, &$init)
  → model->...($init)                            model filters AQL::REQUIRES if present
  → response
```

The `enforceParam`, `enforceFilterKeys`, `enforceFields`, `hasCapability` hooks are the **enforcement points** of *capabilities*. They are called by the controller (typically in `beforeModelCall` or directly in the HTTP verb) depending on the need.

## Controller-side configuration — `Arango::CAPABILITIES`

A single block in the DI definition:

```php
Arango::CAPABILITIES =>
[
    Capability::OBJECT     => '/products' ,                    // common Casbin subject

    ControllerParam::SKIN  =>                                  // capability on ?skin=
    [
        Capability::POLICY   => CapabilityPolicy::SILENT_DOWNGRADE ,
        Capability::FALLBACK => Skin::OFFERS                   ,
        Capability::VALUES   =>
        [
            Skin::OFFERS_FULL => 'products:skin.offers.full' ,                    // REQUIRE (short form)
            Skin::SPECIAL     => [ Capability::DENY => 'products:skin.special' ], // DENY (long form)
        ] ,
    ] ,
] ,
```

Reading:

- `Capability::OBJECT => '/products'` — common Casbin subject for the whole block. Permissions below are prefixed with `products:` by convention.
- `ControllerParam::SKIN` — points to the URL parameter `?skin=`. The pattern works the same for `?filter=`, `?bench=`, `?search=`, etc.
- `Capability::POLICY` — policy applied when the *capability* is refused. Possible values: `SILENT_DOWNGRADE` (replace with `FALLBACK`), `REJECT_403` (return 403), `IGNORE` (let it through), `THROW` (exception).
- `Capability::FALLBACK` — replacement value when the policy is `SILENT_DOWNGRADE`.
- `Capability::VALUES` — map `accepted value → required Casbin permission`. Each entry can be:
  - a **string** = `REQUIRE` short form (the permission is required to allow the value),
  - an **array** `[ Capability::DENY => 'permission' ]` = `DENY` long form (the permission forbids the value).

`Capability::KEYS` is the equivalent for *map*-type parameters like `?filter=`. Each filter **key** is validated against a permission:

```php
ControllerParam::FILTER =>
[
    Capability::POLICY  => CapabilityPolicy::REJECT_403 ,
    Capability::KEYS    =>
    [
        'offers.priceBuying'  => 'products:filter.offers.priceBuying' ,
        'offers.discountRate' => 'products:filter.offers.discountRate' ,
    ] ,
] ,
```

## `Capability::*` sub-key catalog

| Constant | Type | Role |
|---|---|---|
| `Capability::OBJECT` | `string` | Common Casbin subject (typically the resource — `/products`, `/users`). |
| `Capability::POLICY` | `CapabilityPolicy::*` | Policy applied on refusal. |
| `Capability::FALLBACK` | `mixed` | Replacement value when the policy is `SILENT_DOWNGRADE`. |
| `Capability::VALUES` | `array<string,string\|array>` | Value → permission mapping for enumerated parameters (`?skin=`). |
| `Capability::KEYS` | `array<string,string\|array>` | Key → permission mapping for *map* parameters (`?filter=`). |
| `Capability::REQUIRE` | `string` | Required permission (long form of a `VALUES`/`KEYS` entry). |
| `Capability::DENY` | `string` | Permission that **forbids** a value or a key (negation of `REQUIRE`). |
| `Capability::FALLBACKS` | `array<string,string>` | For `KEYS`: refused-key → substitution-key map (instead of drop, remap). |

## `CapabilityPolicy::*` policy catalog

| Policy | Behavior when permission is missing |
|---|---|
| `SILENT_DOWNGRADE` | Replace the value with `FALLBACK` (or drop if no fallback). No error returned — practical to not break a UI when a user loses a permission. |
| `REJECT_403` | Immediate `403 Forbidden` response. To use when the client must know the value is forbidden (e.g. `?bench=true` on a non-admin endpoint). |
| `IGNORE` | Let it through without gating. Equivalent to not configuring the *capability* — exposed for runtime *feature flags*. |
| `THROW` | Throws a server exception. For application *bugs*: if we get here, there's a hole in the declaration. |

## The 7 Capability traits

Enforcement is implemented by seven traits exposed by [`oihana/php-auth`](https://github.com/BcommeBois/oihana-php-auth/tree/main/src/oihana/auth/controllers/traits). Depending on the need, you consume a single specialized trait or the `CapabilityGuardTrait` facade that bundles everything.

| Trait | Role | When to use |
|---|---|---|
| `CapabilityGuardTrait` | **Facade** aggregating the six specialized traits (except `CapabilityAuthorizerTrait`). | Controller consuming several types of *capabilities* — the standard case. |
| `CapabilityContextTrait` | Shared state: `$capabilities`, *kill-switch*, injected *enforcer*. **Required** as soon as another Capability trait is used. | Always via `CapabilityGuardTrait` — no need to touch it directly. |
| `CapabilityParamTrait` | `enforceParam( $request , $paramName )` for **enumerated-value** parameters (`?skin=`). | When you have a closed list of allowed values and you want to gate each one individually. |
| `CapabilityFilterKeysTrait` | `enforceFilterKeys( $request )` for *map*-type parameters (`?filter=`). Gates the filter **keys**. | On resources with sensitive filterable fields (prices, private data). |
| `CapabilityFieldsTrait` | `enforceFields( $payload )` to gate the **body fields** on `PATCH` / `POST` / `PUT`. | When a field must only be modifiable by some users (e.g. `level` on `/roles`). |
| `CapabilityBinaryTrait` | `hasCapability( $request , $paramName )` for **binary** parameters (`?bench=true`) or **cross-cutting actions**. | For *features* not mapped on a value but on the presence of a parameter. |
| `CapabilityAuthorizerTrait` | `buildAuthorizer( $request )` produces a **request-scoped** `Closure(string $subject): bool`. | To provide the *authorizer* to the model layer via `Arango::AUTHORIZER` (gating `AQL::REQUIRES` on edges/joins). |

Standard usage pattern in a custom controller:

```php
use oihana\arango\controllers\DocumentsController ;
use oihana\controllers\traits\CapabilityGuardTrait ;
use Psr\Http\Message\ServerRequestInterface as Request ;

final class ProductsController extends DocumentsController
{
    use CapabilityGuardTrait ;

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;

        // Gate the value of ?skin= against Casbin permissions
        $this->enforceParam( $request , ControllerParam::SKIN , $init ) ;

        // Gate the keys of ?filter=
        $this->enforceFilterKeys( $request , $init ) ;

        // Inject the authorizer for the model layer (AQL::REQUIRES)
        if ( ( $authorizer = $this->buildAuthorizer( $request ) ) !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }
    }
}
```

## The *authorizer* — toward the model

A *capability* can also live **on the model side**, on an *edge* or a *join*: it's `AQL::REQUIRES`, documented in detail in [Edge and join projection](../edges-joins-projection.md#restrict-edge-or-join-projection-to-a-permission--aqlrequires).

The controller knows nothing about the internal authorization system — it **injects a *callable*** that the model consults as needed:

```php
$init[ Arango::AUTHORIZER ] = fn( string $subject ) : bool
                            => $enforcer->enforce( $userId , $subject , 'view' ) ;
```

`CapabilityAuthorizerTrait::buildAuthorizer( $request )` automatically produces this request-scoped *callable* based on the Casbin `CapabilityEnforcer`. The model then filters its `AQL::REQUIRES`-annotated *edges* and *joins* by consulting that *callable* — without ever having to understand Casbin itself.

This is the **separation-of-concerns contract** between `oihana/php-arango` (which knows nothing about auth) and the host project's controller layer (which implements Casbin). When `oihana/php-arango` is one day extracted as a standalone library, the *authorizer* remains injectable from the outside — see the [`oihana/php-arango` ↔ `oihana/api` decoupling chantier](../dependencies.md#local-couplings-to-the-host-project).

## Complete example — `/products?skin=offers.full`

Real case on the `products` resource. Three *skins* exposed on the product catalog:

- `Skin::DEFAULT` — product listing (free for every authenticated user).
- `Skin::OFFERS` — detail page with sale price offers (free for sellers).
- `Skin::OFFERS_FULL` — detail page with **offers + buying prices** (restricted to superadmin and buyers).

Controller definition:

```php
use Acme\enums\Skin ;
use oihana\arango\enums\Arango ;
use oihana\auth\enums\Capability ;
use oihana\auth\enums\CapabilityPolicy ;
use oihana\controllers\enums\ControllerParam ;

Controllers::PRODUCTS => fn( Container $c ) => new ProductsController( $c ,
[
    Arango::MODEL        => Models::PRODUCTS                          ,
    Arango::SKINS        => [ Skin::DEFAULT , Skin::OFFERS , Skin::OFFERS_FULL ] ,
    Arango::SKIN_DEFAULT => Skin::DEFAULT                              ,
    Arango::SKIN_METHODS =>
    [
        HttpMethod::list => Skin::DEFAULT ,
        HttpMethod::get  => Skin::OFFERS  ,
    ] ,

    Arango::CAPABILITIES =>
    [
        Capability::OBJECT => '/products' ,

        ControllerParam::SKIN =>
        [
            Capability::POLICY   => CapabilityPolicy::SILENT_DOWNGRADE ,
            Capability::FALLBACK => Skin::OFFERS ,
            Capability::VALUES   =>
            [
                Skin::OFFERS_FULL => 'products:skin.offers.full' ,
                // Skin::DEFAULT and Skin::OFFERS are free
            ] ,
        ] ,
    ] ,
]) ,
```

Runtime behavior:

- `GET /products?skin=default` (regular seller) → OK, list projection.
- `GET /products/{id}?skin=offers` (seller) → OK, sale projection.
- `GET /products/{id}?skin=offers.full` (seller **without** the `products:skin.offers.full` permission) → the `SILENT_DOWNGRADE` policy silently replaces with `Skin::OFFERS`. The client gets the sale page without buying prices — they don't even see they were refused.
- `GET /products/{id}?skin=offers.full` (buyer **with** the permission) → OK, full projection including buying prices.

The client never needs to request a different URL depending on their role. The server automatically projects what the user is allowed to see.

## See also

- [Skins](skins.md) — complementary projection system (*capabilities* gate the **values** of *skins*).
- [Edge and join projection — `AQL::REQUIRES`](../edges-joins-projection.md#restrict-edge-or-join-projection-to-a-permission--aqlrequires) — *capability* at the model level (edge/join).
- [HTTP filters `?filter=`](../filter.md) — parameter covered by `CapabilityFilterKeysTrait`.
- [Casbin RBAC adapter](../casbin.md) — underlying authorization system.
- [Dependencies — Local couplings](../dependencies.md#local-couplings-to-the-host-project) — the *authorizer* injection contract that keeps `oihana/php-arango` independent.
