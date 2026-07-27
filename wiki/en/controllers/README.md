# Slim controllers

The [`src/oihana/arango/controllers/`](../../../src/oihana/arango/controllers/) folder provides three ready-to-use HTTP controllers that expose a [`Documents` or `Edges` model](../models.md) as RESTful routes. The layer is designed for Slim 4 and a PSR-11 container, but does not depend on any specific implementation beyond the PSR contracts.

| Controller | Role | Typical routes |
|---|---|---|
| `DocumentsController` | Full CRUD on a document collection. | `GET /resource`, `GET /resource/{id}`, `POST /resource`, `PATCH /resource/{id}`, `PUT /resource/{id}`, `DELETE /resource/{id}`, `GET /resource/count`, `GET /resource/last` |
| `EdgesController` | CRUD on an edge collection. | Same verbs, edge semantics (validation `_from`/`_to`). |
| `PropertyController` | Exposes a specific property of a document (GET / PATCH). | `GET /resource/{id}/{property}`, `PATCH /resource/{id}/{property}` |
| `ArrayPropertyController` | Element-level operations of an [array-field](../db/arrays.md) property (add / remove / move / reorder / edit / contains). | `POST\|PUT /resource/{id}/{property}`, `DELETE\|PATCH\|PUT\|GET /resource/{id}/{property}/{value}` |
| `TraversalController` | Navigates a **self-referential** edge (a tree/graph): parent, children, ancestors, descendants. | `GET /resource/{id}/{parent\|children\|ancestors\|descendants}` |
| `ConceptSchemeController` | Exposes a hierarchical thesaurus's **roots** as a SKOS `ConceptScheme`. | `GET /resource/scheme` |

## Detailed pages in this folder

This page is the **controllers overview** (verb signatures, lifecycle hooks, injection traits). The **specialized mechanisms** consumed by the controllers are each documented on a dedicated page:

- [**Payloads**](payloads.md) — the `PayloadsTrait` layer that extracts, types and transforms the incoming HTTP *body*. `AQLType` catalog, `Arango::PAYLOAD` keys, pre-extraction i18n validation, `EDGE` type and recursive nesting.
- [**Rules**](rules.md) — the validation layer applied after payload preparation. `Arango::RULES` + `Arango::CUSTOM_RULES`, `rules() / min() / max() / between()` helpers, "final tag" pattern, vendor `Rules::*` catalog + project `CustomRules::*` catalog, 422 error format.
- [**Skins**](skins.md) — the *output* projection layer. Catalog of the 12 canonical *skins*, `Arango::SKINS` / `SKIN_DEFAULT` / `SKIN_METHODS` keys, `Skin::INTERNAL` special case (server-only projection).
- [**Capabilities**](capabilities.md) — fine gating of a parameter **value** (`?skin=`, `?filter=`) or a body **field**, orthogonal to Casbin. `Arango::CAPABILITIES`, 7 Capability traits, *authorizer* injection pattern toward the model (`AQL::REQUIRES`).

## `DocumentsController`

### Exposed HTTP methods

`DocumentsController` is composed by aggregating 8 CRUD traits, one per HTTP verb. Each maps the verb to the matching model method.

| Controller method | HTTP verb | Model method | Trait |
|---|---|---|---|
| `list()` | `GET /resource` | `list()` | `DocumentsControllerListTrait` |
| `get()` | `GET /resource/{id}` | `get()` | `DocumentsControllerGetTrait` |
| `last()` | `GET /resource/last` | `last()` | `DocumentsControllerLastTrait` |
| `count()` | `GET /resource/count` | `count()` | `DocumentsControllerCountTrait` |
| `post()` | `POST /resource` | `insert()` | `DocumentsControllerPostTrait` |
| `patch()` | `PATCH /resource/{id}` | `update()` | `DocumentsControllerPatchTrait` |
| `put()` | `PUT /resource/{id}` | `replace()` | `DocumentsControllerPutTrait` |
| `delete()` | `DELETE /resource/{id}` | `delete()` | `DocumentsControllerDeleteTrait` |

Every method shares the signature:

```php
public function <verb>
(
    ?Request  $request  = null ,
    ?Response $response = null ,
    array     $args     = []   ,
    array     $init     = []
) : mixed
```

The `$init` parameter is an extension point: an override can pre-fill it to change the call's behavior without touching the HTTP request.

### DI definition

```php
use DI\Container ;
use oihana\arango\controllers\DocumentsController ;
use oihana\arango\enums\Arango ;
use oihana\controllers\enums\Skin ;

return
[
    Controllers::USERS => fn( Container $c ) => new DocumentsController( $c ,
    [
        Arango::MODEL        => Models::USERS         ,
        Arango::LIMIT        => 50                    ,
        Arango::SKINS        => [ Skin::DEFAULT , Skin::FULL ] ,
        Arango::SKIN_DEFAULT => Skin::DEFAULT         ,
        Arango::SKIN_METHODS =>
        [
            HttpMethod::list => Skin::DEFAULT ,
            HttpMethod::get  => Skin::FULL    ,
        ] ,
    ]) ,
] ;
```

Main configuration keys:

| Key | Description |
|---|---|
| `Arango::MODEL` | DI identifier of the consumed [`Documents`/`Edges`](../models.md) model. |
| `Arango::LIMIT` | Default pagination limit. |
| `Arango::SKINS` | Whitelist of *skins* accepted via `?skin=`. |
| `Arango::SKIN_DEFAULT` | *Skin* applied in the absence of `?skin=`. |
| `Arango::SKIN_METHODS` | Different default *skin* per verb (typically `default` for `list`, `full` for `get`). |
| `Arango::META_ONLY` | Durable default for the "metadata only" mode (no documents): `true` for a facet/bounds-probe endpoint. Still overridable per request with `?metaOnly=`. See [facets](../db/facets.md). |

### Declare routes

Controllers are consumed by Slim *routes* defined in `definitions/routes.php`. Convention:

```php
use oihana\api\routes\GetRoute  ;
use oihana\api\routes\PostRoute ;
use oihana\api\routes\DeleteRoute ;

return
[
    // GET /users — list
    // Warning: GetRoute calls `get()` by default, so REQUIRED for listing
    Routes::USERS_LIST => fn( Container $c ) => new GetRoute( $c ,
    [
        Route::CONTROLLER_ID => Controllers::USERS ,
        Route::ROUTE         => '/users'            ,
        Route::METHOD        => 'list'              ,        // REQUIRED
    ]) ,

    // GET /users/{id}
    Routes::USERS_GET => fn( Container $c ) => new GetRoute( $c ,
    [
        Route::CONTROLLER_ID => Controllers::USERS         ,
        Route::ROUTE         => '/users/{id:[a-z0-9-]+}' ,
    ]) ,

    // POST /users
    Routes::USERS_POST => fn( Container $c ) => new PostRoute( $c ,
    [
        Route::CONTROLLER_ID => Controllers::USERS ,
        Route::ROUTE         => '/users'            ,
    ]) ,

    // ... etc.
] ;
```

> Classic pitfall: `GetRoute` defaults to the `get()` method. For **listing**, you must explicitly specify `Route::METHOD => 'list'`. Forgetting this detail causes `GET /users` (without `id`) to crash looking up a non-existing document.

## Extend `DocumentsController`

The recommended pattern to add custom logic (cross-cutting filter, validation, enrichment, authorization hooks) is to **subclass** the controller and override the appropriate verb — strictly preserving the parent signature.

```php
use oihana\arango\controllers\DocumentsController ;
use oihana\arango\controllers\traits\inject\InjectFilterTrait ;
use Psr\Http\Message\ServerRequestInterface as Request ;
use Psr\Http\Message\ResponseInterface as Response ;

final class MyUsersController extends DocumentsController
{
    use InjectFilterTrait ;

    public function list
    (
        ?Request  $request  = null ,
        ?Response $response = null ,
        array     $args     = []   ,
        array     $init     = []
    ) : mixed
    {
        $userKey = $this->getCurrentUserKey( $request ) ;
        $init    = $this->injectFilter( $init , 'agent' , $userKey ) ;

        return parent::list( $request , $response , $args , $init ) ;
    }
}
```

**Important**: respect the **exact signature** of the parent (including `$init = []` at the end). A degraded signature breaks polymorphism and prevents lifecycle hooks from firing.

## Lifecycle hooks

`DocumentsController` consumes [`ModelCallTrait`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/traits/ModelCallTrait.php), which sets two *hooks* automatically invoked around every CRUD operation: `beforeModelCall` and `afterModelCall`.

```php
final class UsersController extends DocumentsController
{
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;
        // ... validation, cross-cutting filter (the permission authorizer is already posed by the base)
    }

    protected function afterModelCall
    (
        ?Request  $request          ,
        array     &$init            ,
        mixed     &$result
    ) : void
    {
        parent::afterModelCall( $request , $init , $result ) ;
        // ... response enrichment, logging, audit
    }
}
```

Advantage: **a single override covers all HTTP verbs**. No need to repeat cross-cutting logic in `list()`, `get()`, `post()`, etc.

### ⚠️ `Arango::CONDITIONS` does not mean the same thing on every operation

That advantage has one sharp edge, and it is worth knowing before you write your first hook: `conditions` is spelled the same in every `$init`, but the model reads it with **two different dictionaries** depending on the operation.

> **Mostly resolved.** `Arango::CONDITIONS` now means AQL predicates on `update()` and `replace()` too, and the compression predicates have their own key, `Arango::OMIT_WHEN`. The table below describes the transition state: the old write meaning is still accepted, with a deprecation logged, until the next release.

| Operation | Expected type | Meaning |
|---|---|---|
| `get()`, `list()`, `last()`, `count()`, `exist()`, `delete()`, `update()`, `replace()` | `string[]` | AQL predicates appended to the query's `FILTER` |
| `insert()`, `update()`, `replace()`, `upsert()` | `callable[]` — **deprecated**, use `Arango::OMIT_WHEN` | which **attributes to drop from the payload** before writing (the null-compression guards) |

The situation. A hook posing a scope on every model call — the very pattern this section recommends:

```php
protected function beforeModelCall( ?Request $request , array &$init ) : void
{
    $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , 'doc.published == @published' ] ;
    $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , 'published' => true ] ;

    parent::beforeModelCall( $request , $init ) ;
}
```

It now does what you want on `GET`, on `PATCH` and on `PUT` alike — the predicate joins the write's `FILTER`, so an update aimed at a document outside the scope matches nothing and writes nothing. It used to reach `compress()`, which expects callables, and answer:

```
InvalidArgumentException: All conditions in the array must be callable.
→ HTTP 500
```

`POST` remains the exception, and always will: an `INSERT` creates a document, so there is no existing one to filter. Scope a creation by refusing it upstream, not by narrowing a query that has no `FILTER`.

**The write meaning now has a name of its own: `Arango::OMIT_WHEN`.** Use it for the compression predicates, and the shared key stops being ambiguous on your side:

```php
$model->update
([
    Arango::VALUE     => 'k1' ,
    Arango::DOC       => [ 'name' => 'Marc' , 'nickname' => null ] ,
    Arango::OMIT_WHEN => [ fn( $value ) => $value === null || $value === '' ] ,
]) ;
```

`Arango::CONDITIONS` is still honoured on the four writes when it carries callables, with a deprecation logged so a migration can be measured rather than guessed. A mixed array is split rather than refused: the callables compress the payload, the strings go to the `FILTER`.

If you need to tell reads from writes inside a hook anyway — to pose a predicate only on reads, or a different one on each — write inits carry `Arango::DOC`, the payload about to be written, and read inits never do:

```php
protected function beforeModelCall( ?Request $request , array &$init ) : void
{
    if ( !array_key_exists( Arango::DOC , $init ) ) // a read
    {
        $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , 'doc.published == @published' ] ;
        $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , 'published' => true ] ;
    }

    parent::beforeModelCall( $request , $init ) ;
}
```

Consequence to accept if you do gate it that way: the write is then not scoped by its own `FILTER`, and must be gated upstream — probe the document through a scoped `exist()` before writing, which is the pattern [`PropertyController` applies](#scoping-a-property-controller). Unless you have a reason to, posing the predicate on both is simpler and scopes the write directly.

## Route args reach the model (`Arango::ARGS`)

Take the route `/workspaces/{workspace}/things/{id}`. Slim hands the action its
placeholders — `[ 'workspace' => 'w1' , 'id' => '42' ]` — as the `$args` argument.
Every handler folds them into the `$init` it passes to the model, under the
`Arango::ARGS` key:

```php
// DELETE /workspaces/w1/things/42
$init = [ Arango::ARGS => [ 'workspace' => 'w1' , 'id' => '42' ] , Arango::VALUE => [ '42' ] ] ;
```

Reads (`list`, `get`, `last`) always did this; **writes do it too**: `post()` → `insert()`,
`patch()` / `put()` → `update()` / `replace()`, `delete()` → `delete()`, and the
existence probe (`exist()`) that guards the update and the delete alike. The key is
**always present** (an empty array when the route carries no placeholder), and the route
args **win** over an `Arango::ARGS` entry already sitting in the incoming `$init`.

Two consumers benefit:

- **`Filter::URL` fields**, whose `Field::PATH` placeholders are resolved from
  `Arango::ARGS` (see [URL fields](../db/helpers.md#url-fields--filterurl)) — the document
  returned by a write now carries the same `url` as the one returned by a read.
- **Your `beforeModelCall()` / `afterModelCall()` overrides and the model signals**
  (`BeforeInsert`, `AfterUpdate`, `BeforeDelete`, …), whose `context` *is* that `$init` —
  a tenant or workspace segment of the URL is readable there without touching the request
  again.

## `InjectFilterTrait`

**Namespace**: `oihana\arango\controllers\traits\inject\InjectFilterTrait`

Allows programmatic filter injection via `$init`. Injected filters are merged with URL filters but **do not appear** in the response URL (`url` field of the JSON).

```php
use oihana\arango\controllers\traits\inject\InjectFilterTrait ;
use oihana\arango\models\enums\filters\FilterComparator ;
use oihana\arango\models\enums\filters\FilterParam ;

// Simple filter
$init = $this->injectFilter( $init , 'userId' , $userKey ) ;

// With operator
$init = $this->injectFilter
(
    $init , 'created' , '2026-01-01' , FilterComparator::GE
) ;

// With alteration
$init = $this->injectFilter
(
    $init , 'name' , 'john' , FilterComparator::EQ , 'lower'
) ;

// Several filters at once
$init = $this->injectFilters( $init ,
[
    [ FilterParam::KEY => 'agent'   , FilterParam::VAL => $userKey ] ,
    [ FilterParam::KEY => 'method'  , FilterParam::VAL => 'DELETE' ] ,
    [ FilterParam::KEY => 'created' , FilterParam::VAL => '2026-01-01' , FilterParam::OP => FilterComparator::GE ] ,
]) ;
```

**How it works**: overrides `prepareFilter()` to merge URL filters (visible in the response URL) with injected filters (invisible, stored in `$init['__injectedFilters']`).

## `InjectAuthorizerTrait`

**Namespace**: `oihana\arango\controllers\traits\inject\InjectAuthorizerTrait`

Allows injecting an *authorizer* `Closure(string $subject): bool` that the AQL framework will consult to decide whether to include an `AQL::REQUIRES`-marked *edge* / *join*. See [Field projection](../projection.md#permission-gated-edges-and-joins--aqlrequires).

> **Note.** In production (Casbin + *request-scoped*) you usually have **nothing to wire**: `DocumentsController` already poses the permission authorizer automatically as soon as the authorization stack is registered in the DI container (see [Projection — automatic wiring](../projection.md#wiring-on-the-controller-side--automatic-from-the-base)). `InjectAuthorizerTrait` is only for a **stable** callable known at construction and not bound to the request (CLI batch, test, callable resolved straight from the container).

```php
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

For the *request-scoped* pattern with Casbin (the most common in production), nothing to do: the base wires it automatically (see the note above).

## `EdgesController`

Variant of `DocumentsController` backed by an [`Edges`](../models.md#the-edges-class) model. Same 8 verbs, adapted semantics:

- `post()` validates `_from`/`_to` before insertion.
- `delete()` triggers the `afterDelete` *signal* cascade.
- Different parameterized routes: `/users/{from}/has-roles/{to}` to target a specific edge.

```php
return
[
    Controllers::USER_HAS_ROLES => fn( Container $c ) => new EdgesController( $c ,
    [
        Arango::MODEL => Models::USER_HAS_ROLES ,
    ]) ,
] ;
```

## `PropertyController`

Exposes **a specific property** of a document as a sub-resource. Useful for properties that have their own logic (validation, computation) without justifying a separate collection.

| Verb | Method | Trait |
|---|---|---|
| `get()` | `GET /resource/{id}/{property}` | `PropertyControllerGetTrait` |
| `patch()` | `PATCH /resource/{id}/{property}` | `PropertyControllerPatchTrait` |

```php
return
[
    Controllers::USERS_AVATAR => fn( Container $c ) => new PropertyController( $c ,
    [
        Arango::MODEL    => Models::USERS  ,
        Arango::PROPERTY => 'avatar'        ,
    ]) ,
] ;
```

### Scoping a property controller

A sub-resource route is a door onto the same document as `/resource/{id}`. If that main route is scoped — only some documents are visible to the caller — the sub-resource must be scoped too, otherwise `/resource/{id}/avatar` answers for documents `/resource/{id}` refuses to show.

`PropertyController` carries the same **authorization seat** as `DocumentsController`: it resolves the capability enforcer and the permission-subject resolver from the container, poses the request-scoped authorizer under `Arango::AUTHORIZER` (so the `Field::REQUIRES` / `AQL::REQUIRES` gates of the projection layer apply), and wraps its model reads in the [lifecycle hooks](#lifecycle-hooks).

The lib supplies the seat, never the rule. A consumer supplies the predicate:

```php
final class AvatarController extends PropertyController
{
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , 'doc.published == @published' ] ;
        $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , 'published' => true ] ;

        parent::beforeModelCall( $request , $init ) ; // keeps the authorizer
    }
}
```

`Arango::CONDITIONS` lands in the query's `FILTER`, `Arango::BINDS` is merged into the bind variables — both are honoured by the model as-is, nothing else is needed.

Conditions that do not depend on the request need no subclass at all: declare them once in the controller's `$init` and they reach the read directly.

#### Where the hook runs, and where it does not

| Call | Hooked | Why |
|---|---|---|
| `get()` | ✅ | the read to scope |
| the post-`patch()` reload | ✅ | also a read — a write response that bypassed the scope would hand back what the scope withholds |
| the existence probe of `patch()` and of the six array operations | ✅ | this is where a scope bites: out of scope → 404, and the write is never reached |
| `update()` and the array writes | ❌ | see below |

The writes are deliberately left alone. `Arango::CONDITIONS` is **overloaded**: a list of AQL predicates on the read path, a list of *callables* on the write path (the null-compression guards of `prepareDocumentClause()`). A hook posing a read scope on every model call would therefore raise `All conditions in the array must be callable` instead of scoping anything. Scoping the existence probe is both safer and sufficient — a document outside the scope is reported missing before any write is attempted.

#### A filtered document answers 200, not 404

When the scope filters the document out, `get()` answers **200 with a null result** — exactly like an unknown identifier, and exactly like a visible document whose property is simply absent. The three cases are indistinguishable on purpose: answering 404 on the first two would tell a caller which one it hit, which is the inference the scope exists to prevent.

#### Without an authorization stack

No enforcer, no resolver, or no authenticated user (CLI, tests, an application that never wired auth) → no authorizer is posed and the projection layer falls open. A controller that does not subclass and does not carry the stack behaves exactly as it did before the seat existed.

## `ArrayPropertyController`

Extends [`PropertyController`](#propertycontroller) to expose the **element-level operations** of a property declared as an **embedded array field** ([`AQL::ARRAYS`](../db/arrays.md)): add, remove, move, reorder, edit an element, test its presence — on top of the inherited `get()` (read the whole array) and `patch()` (replace the whole array).

| Verb | Method | Route | Model operation |
|---|---|---|---|
| `addItem()` | `POST` | `/resource/{id}/{property}` | `arrayInsert` |
| `reorderItems()` | `PUT` | `/resource/{id}/{property}` | `arrayReorder` |
| `removeItem()` | `DELETE` | `/resource/{id}/{property}/{value}` | `arrayRemove` |
| `moveItem()` | `PATCH` | `/resource/{id}/{property}/{value}` | `arrayMove` |
| `updateItem()` | `PUT` | `/resource/{id}/{property}/{value}` | `arrayUpdate` |
| `hasItem()` | `GET` | `/resource/{id}/{property}/{value}` | `arrayContains` |

The six methods live in `ArrayPropertyControllerTrait`.

> `PATCH` and `PUT` share the **element** path, but not the same intent: the **verb** is what tells them apart — `PATCH` **moves** the element, `PUT` **edits** it. On the **property** path, `PUT` replaces the **order** of the whole array.

### Element value: URL or body

The element is resolved from the URL `{value}` placeholder (handy for **scalars**: ids, tags), otherwise from the request **body** (key `value`) — use the body for **complex** (object) values that cannot travel in a URL. `addItem` reads the value from the body (plus an optional `side` `left`/`right`); `moveItem` reads `position` from the body.

### Targeting an element by its key

When the model declares an [`Arango::ITEM_KEY`](../db/arrays.md#targeting-an-element-by-its-key-arangoitem_key) on the property, `{value}` is no longer the element: it is **its key**. That is precisely what makes an array of **objects** addressable over REST — `DELETE /playlists/42/chapters/c1` instead of a whole object in the body.

Two consequences on the HTTP side:

- `moveItem` and `updateItem` answer **`404`** when no element carries the requested key. The model turns both cases into a no-op (nothing merged, nothing reordered), so the document it returns is enough to notice: **no extra query**.
- The comparison is **strict**, like AQL's `==` on a document attribute. A numeric key requested from a URL (hence a string) matches nothing — neither in the database nor in the controller. Both say "not found" at the same moment.

### `updateItem`: the body IS the patch

`PUT /resource/{id}/{property}/{value}` merges a partial patch into the designated element. The request body **is** the patch, with no envelope:

```http
PUT /playlists/42/chapters/c1
Content-Type: application/json

{ "rating": 5 }
```

The verb already says the element is being edited: nothing needs to name it again in the body. The merge is partial — the patch attributes overwrite theirs, the others are kept.

### `reorderItems`: the whole order in one request

`PUT /resource/{id}/{property}` applies **a whole order at once**, where `moveItem` moves one element at a time — what a drag and drop interface needs once it knows the final order. The ordered keys travel in the **body**, under `value`, like `addItem` — the other operation targeting the **property** rather than one of its elements:

```http
PUT /invoices/42/lines
Content-Type: application/json

{ "value": [ "l3", "l1", "l2" ] }
```

A **partial** list reorders what it names and **keeps the rest**, appended behind it: an interface bug sending only a subset cannot wipe lines out. An unknown key is skipped, an empty list changes nothing. See [`arrayReorder`](../db/arrays.md#arrayreorder) for the details.

### Error codes

| Code | When |
|---|---|
| `400 Bad Request` | the targeted property is not declared in the model's `AQL::ARRAYS` |
| `404 Not Found` | the owner document does not exist; or (`hasItem`) the value is absent from the array; or (`moveItem`/`updateItem` by key) no element carries the requested key |
| `422 Unprocessable Entity` | the operation **does not exist** on that property: `moveItem`/`reorderItems` on a `sortedSet` field, `updateItem`/`reorderItems` on a property with no item key, or a property declared both `sortedSet` and [numbered](../db/arrays.md#numbering-the-elements-arangoposition_key) |

All six operations — `hasItem` included — run the owner existence probe first. It is the seam a [scope](#scoping-a-property-controller) acts on: an owner outside the scope is reported missing, and neither the write nor the membership answer is reached. A membership answer on a document the caller may not see would be a disclosure of its own.

**The 422 rule, in one sentence:** "this operation does not exist on that field" is a **request** the property cannot satisfy, not a server failure. The model states the rule **once** — it raises an `UnsupportedOperationException` — and the controller's shared skeleton turns every one of them into that same status. No guard is rewritten operation by operation.

> **Why `updateItem` and `reorderItems` refuse a property with no key.** For `updateItem`, the element could only be designated by a byte-for-byte copy of itself — which the patch being applied invalidates at once: the second identical call would match nothing. For `reorderItems`, without an attribute identifying the elements there is simply nothing to order. Better to refuse than to serve an operation that only works once, or not at all.

### Full wiring (model + controller + routes)

```php
use oihana\arango\controllers\ArrayPropertyController ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\enums\ArrayMode ;
use oihana\arango\routes\ArrayPropertyRoute ;
use oihana\routes\Route ;

// 1. The model declares the array field (mode + counter). Bonus: on document
//    creation (POST /playlists), `tracks` is seeded to [] automatically
//    (and `numberOfTracks` to 0).
//    An array of objects additionally declares the attribute identifying its
//    elements, which makes `{value}` addressable: Arango::ITEM_KEY => 'id'.
Models::PLAYLIST => fn( Container $c ) => new Documents( $c ,
[
    AQL::COLLECTION => 'Playlist' ,
    AQL::ARRAYS     => [ 'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ] ] ,
]) ,

// 2. The controller, configured for the 'tracks' property.
Controllers::PLAYLIST_TRACKS => fn( Container $c ) => new ArrayPropertyController( $c ,
[
    Arango::MODEL    => Models::PLAYLIST ,
    Arango::PROPERTY => 'tracks' ,
]) ,

// 3. The routes: a single entry via ArrayPropertyRoute.
Routes::PLAYLIST_TRACKS => fn( Container $c ) => new ArrayPropertyRoute( $c ,
[
    Route::CONTROLLER_ID => Controllers::PLAYLIST_TRACKS ,
    Route::ROUTE         => '/playlists/{id}/tracks' ,
]) ,
```

Generates `POST|PUT /playlists/{id}/tracks` (addItem / reorderItems) and `DELETE|PATCH|PUT|GET /playlists/{id}/tracks/{value}` (removeItem / moveItem / updateItem / hasItem).

> `arrayPurgeRef` (remove a value from **every** document that references it) is **not** exposed over HTTP: it is a cascade operation, triggered application-side through an `afterUpdate`/`afterDelete` listener (see [Embedded array fields](../db/arrays.md#propagating-a-change-to-parent-documents)).

## `TraversalController`

Navigates a **self-referential** edge — a graph whose two ends target the same vertex collection (a category tree, an org chart, a comment thread) — and returns the traversed vertices, hydrated with the target collection's schema. A **single instance** exposes the four navigation methods; the edge is injected once through `TraversalController::EDGE`.

| Method | Verb | Route | Direction | Transitive |
|---|---|---|---|---|
| `getParent()` | `GET` | `/resource/{id}/parent` | INBOUND | no (one, or `null`) |
| `getChildren()` | `GET` | `/resource/{id}/children` | OUTBOUND | no (direct) |
| `getAncestors()` | `GET` | `/resource/{id}/ancestors` | INBOUND | yes (up to the root) |
| `getDescendants()` | `GET` | `/resource/{id}/descendants` | OUTBOUND | yes (full sub-tree) |

The transitive methods accept a `?depth=N` query parameter, clamped to `TraversalController::DEFAULT_MAX_DEPTH` (default: the full sub-tree). Vertices hydrate through the edge's target model (`Edges::get*Vertices()`), so a **query-projected field survives the traversal**.

The plural methods' envelope (children, ancestors, descendants) carries `count` **and** `total`, both equal to the number of traversed vertices: the traversal is not paginated, so `count == total`.

### Filtering the traversed vertices (`?filter=`)

All four methods accept a `?filter=` query parameter — the [same JSON DSL](../db/filter.md) as the `Documents` surface — restricting the traversal to the vertices that match:

`GET /categories/5/descendants?filter={"key":"status","op":"eq","val":"published"}` → only the published descendants.

**How it stays safe.** A traversal inlines its `FILTER` slot verbatim (a server-only knob), so the client JSON is **never** dropped in raw. It is first *compiled* by the edge model's gated engine, targeting the traversed `vertex`, into `FILTER vertex.status == @bind` — which means the two `Documents` guard rails apply unchanged:

- **Whitelist** — only the attributes declared in the edge model's `AQL::FILTERS` are filterable; an undeclared attribute is dropped, so `?filter=` can never reach an unexposed field.
- **Authorizer** — when an authorization stack is wired, the request authorizer gates `Field::REQUIRES` both on the compiled predicate and on the vertex projection (`returnFields()`): a hidden attribute cannot be probed through the traversal. Without a stack it falls open (backward compatible).

**Semantics — `?filter=` hides, it does not prune.** On a transitive traversal (`ancestors` / `descendants`), a non-matching vertex is removed from the returned **flat list**, but the traversal still descends *through* it — so a matching grand-child survives even when its parent is filtered out. This is the correct behaviour for a flat list; to reconstruct a `children[]` tree from it, see the note on holes in [`buildTree()`](../edges-joins-projection.md).

### Cutting whole branches (`?prune=`)

Where `?filter=` hides a vertex but keeps descending, `?prune=` **cuts the whole branch under a non-matching vertex**. Same JSON DSL, same guard rails (whitelist + authorizer). Take `root(published) → A(published) → B(draft) → C(published)`:

| Query | Result | Why |
|---|---|---|
| `?filter={status:published}` | `root, A, C` | B hidden, but the traversal descends through it → C reappears |
| `?prune={status:published}` | `root, A` | the branch under B is cut → C is never reached |

`?prune=cond` excludes the non-matching **boundary** vertex too (its condition also joins the `FILTER`) and never walks its sub-tree — it compiles to `FILTER vertex.status == @b` **and** `PRUNE !( vertex.status == @b )`. So you get the clean sub-tree of matching vertices, no stray boundary leaves.

- **Direction** — `?prune=` is **rejected with `400`** on the inbound methods (`getParent`, `getAncestors`): pruning while climbing to the root is ill-defined. It applies to `getChildren` / `getDescendants` (on the direct `getChildren` it is a harmless no-op — there is nothing below depth 1).
- **Composes with `?filter=`** — both may be sent at once. Every condition narrows the returned set (they `AND` in the `FILTER`); only the prune one also stops the descent. E.g. `?filter={lang:fr}&prune={status:published}` returns the published sub-tree, further restricted to French vertices.

### Full wiring (edge + controller + routes)

The four sub-routes are declared in one entry with [`TraversalRoute`](../../../src/oihana/arango/routes/TraversalRoute.php), which maps each suffix to the matching controller method (via `Route::METHOD`, no magic strings) — the twin of `ArrayPropertyRoute`.

```php
use oihana\arango\controllers\TraversalController ;
use oihana\arango\routes\TraversalRoute ;
use oihana\routes\Route ;

// 1. The self-referential edge model (both ends target the same collection).
Models::CATEGORY_TREE => fn( Container $c ) => new Edges( $c ,
[
    AQL::COLLECTION => 'category_has_subcategory' ,
    // … from = to = the categories collection …
]) ,

// 2. The controller, configured with that edge.
Controllers::CATEGORIES_TRAVERSAL => fn( Container $c ) => new TraversalController( $c ,
[
    TraversalController::EDGE => Models::CATEGORY_TREE ,
]) ,

// 3. The four sub-routes, in a single entry. Register it BEFORE the generic
//    document route so the literal suffixes are matched first.
Routes::CATEGORIES_TREE => fn( Container $c ) => new TraversalRoute( $c ,
[
    Route::CONTROLLER_ID => Controllers::CATEGORIES_TRAVERSAL ,
    Route::ROUTE         => '/categories' ,
]) ,
```

Generates `GET /categories/{id:[0-9]+}/{parent|children|ancestors|descendants}`. The `{id}` placeholder is configurable via `Route::ROUTE_PLACEHOLDER`.

## `ConceptSchemeController`

Exposes a hierarchical thesaurus as a SKOS [`ConceptScheme`](https://www.w3.org/TR/skos-reference/#schemes): its `hasTopConcept` is the set of **roots** (the concepts that have no broader parent), assembled on the fly from the underlying `Documents` model. Read-only and generic — a single entry point, so a plain `GetRoute` is enough (no dedicated route class).

| Init key | Role | Default |
|---|---|---|
| `MODEL` | the thesaurus `Documents` model | — |
| `TITLE` | the scheme display name | `''` |
| `RELATION` | the broader relation key whose **absence** marks a root | `Oihana::BROADER` |
| `SKIN` | the skin used to project the roots | `Skin::FULL` |

It honours `?sort` (e.g. `id`, `name`, `created`, `modified`), `?search` and `?filter` on the roots — the model applies its own `SORTABLE` / `SEARCHABLE` / `AQL::FILTERS` whitelist. Nothing is persisted.

### Filtering the roots (`?filter=`)

The `?filter=` query parameter accepts the [same JSON DSL](../db/filter.md) as the `Documents` surface and narrows the roots. It is **ANDed** with the root constraint (« has no broader parent »), which always stays the first, non-negotiable operand:

`?filter={"key":"inScheme","op":"eq","val":"animals"}` → the roots **of the `animals` scheme**, conceptually `FILTER ( <is a root> ) && ( doc.inScheme == @value )`.

A client `or` group cannot loosen the scope: the URL filter enters as a **single** operand, so `["or", … ]` keeps its own parentheses — you get `root && ( a || b )`, never `root || a || b`. Same invariant as `InjectFilterTrait`.

Two guard rails, identical to the `Documents` controllers:

- **Whitelist** — only the attributes declared in the model's `AQL::FILTERS` are filterable; anything else is dropped (logged), so `?filter=` can never reach an undeclared field.
- **Authorizer** — when an authorization stack is wired (`CapabilityEnforcerInterface` + `PermissionSubjectResolverInterface` in the container), the request authorizer gates `Field::REQUIRES`: an attribute hidden from the caller neutralizes its predicate to `false` instead of leaking, closing the filter-oracle. Without a stack, it falls open (backward compatible).

```php
use oihana\arango\controllers\ConceptSchemeController ;
use oihana\routes\http\GetRoute ;
use oihana\routes\Route ;

Controllers::CATEGORIES_SCHEME => fn( Container $c ) => new ConceptSchemeController( $c ,
[
    ConceptSchemeController::MODEL => Models::CATEGORIES ,
    ConceptSchemeController::TITLE => 'Product categories' ,
]) ,

Routes::CATEGORIES_SCHEME => fn( Container $c ) => new GetRoute( $c ,
[
    Route::CONTROLLER_ID => Controllers::CATEGORIES_SCHEME ,
    Route::ROUTE         => '/categories/scheme' ,
]) ,
```

Returns `{ "@type": "ConceptScheme", "name": "Product categories", "hasTopConcept": [ … roots … ] }`.

The response envelope also carries `count` **and** `total`, both equal to the number of top concepts (`count(hasTopConcept)`). The `/scheme` is not paginated — every root ships — so `count == total` and there is no `limit` / `offset`. Handy to show "N families" without recounting on the UI side.

## `PayloadsTrait`

**Namespace**: `oihana\arango\controllers\traits\PayloadsTrait`

Cross-cutting trait consumed by all controllers. Centralizes the normalization of incoming HTTP payloads (JSON body, *form-urlencoded*) and validation against the model's `AQL::FILLABLE`. Documented in detail in [Models](../models.md) (key `AQL::FILLABLE`).

## Trait summary catalog

| Trait | Family | Role |
|---|---|---|
| `DocumentsControllerListTrait` | Verb | `list()` |
| `DocumentsControllerGetTrait` | Verb | `get()` |
| `DocumentsControllerLastTrait` | Verb | `last()` |
| `DocumentsControllerCountTrait` | Verb | `count()` |
| `DocumentsControllerPostTrait` | Verb | `post()` |
| `DocumentsControllerPatchTrait` | Verb | `patch()` |
| `DocumentsControllerPutTrait` | Verb | `put()` |
| `DocumentsControllerDeleteTrait` | Verb | `delete()` |
| `DocumentsControllerUpdateTrait` | Verb | internal helper, factors `patch`/`put` |
| `PropertyControllerGetTrait` | Verb | property `get()` |
| `PropertyControllerPatchTrait` | Verb | property `patch()` |
| `PayloadsTrait` | Cross-cutting | Payload normalization and validation. |
| `InjectFilterTrait` | Extension | Transparent filter injection. |
| `InjectAuthorizerTrait` | Extension | *Authorizer* injection on *edges*/*joins*. |

## See also

- [`Documents` and `Edges` models](../models.md) — the underlying business layer.
- [HTTP filters `?filter=`](../db/filter.md) — URL syntax consumed by controllers.
- [Internal filtering](../db/filter-internal.md) — `InjectFilterTrait` and `AQL::CONDITIONS`.
- [Field projection](../projection.md) — `Skin`, `AQL::REQUIRES`, *authorizer*.
- [Symfony Console commands](../commands.md) — parallel CLI exposition.
