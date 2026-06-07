# Slim controllers

The [`src/oihana/arango/controllers/`](../../../src/oihana/arango/controllers/) folder provides three ready-to-use HTTP controllers that expose a [`Documents` or `Edges` model](../models.md) as RESTful routes. The layer is designed for Slim 4 and a PSR-11 container, but does not depend on any specific implementation beyond the PSR contracts.

| Controller | Role | Typical routes |
|---|---|---|
| `DocumentsController` | Full CRUD on a document collection. | `GET /resource`, `GET /resource/{id}`, `POST /resource`, `PATCH /resource/{id}`, `PUT /resource/{id}`, `DELETE /resource/{id}`, `GET /resource/count`, `GET /resource/last` |
| `EdgesController` | CRUD on an edge collection. | Same verbs, edge semantics (validation `_from`/`_to`). |
| `PropertyController` | Exposes a specific property of a document (GET / PATCH). | `GET /resource/{id}/{property}`, `PATCH /resource/{id}/{property}` |
| `ArrayPropertyController` | Element-level operations of an [array-field](../db/arrays.md) property (add / remove / move / contains). | `POST /resource/{id}/{property}`, `DELETE\|PATCH\|GET /resource/{id}/{property}/{value}` |

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
        // ... authorizer injection, validation, cross-cutting filter
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

Allows injecting an *authorizer* `Closure(string $subject): bool` that the AQL framework will consult to decide whether to include an `AQL::REQUIRES`-marked *edge* / *join*. See [Edge and join projection](../edges-joins-projection.md#restrict-edge-or-join-projection-to-a-permission--aqlrequires).

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

For the *request-scoped* pattern with Casbin (the most common in production), see `CapabilityAuthorizerTrait` from the host project.

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

## `ArrayPropertyController`

Extends [`PropertyController`](#propertycontroller) to expose the **element-level operations** of a property declared as an **embedded array field** ([`AQL::ARRAYS`](../db/arrays.md)): add, remove, move an element, test its presence — on top of the inherited `get()` (read the whole array) and `patch()` (replace the whole array).

| Verb | Method | Route | Model operation |
|---|---|---|---|
| `addItem()` | `POST` | `/resource/{id}/{property}` | `arrayInsert` |
| `removeItem()` | `DELETE` | `/resource/{id}/{property}/{value}` | `arrayRemove` |
| `moveItem()` | `PATCH` | `/resource/{id}/{property}/{value}` | `arrayMove` |
| `hasItem()` | `GET` | `/resource/{id}/{property}/{value}` | `arrayContains` |

The four methods live in `ArrayPropertyControllerTrait`.

### Element value: URL or body

The element is resolved from the URL `{value}` placeholder (handy for **scalars**: ids, tags), otherwise from the request **body** (key `value`) — use the body for **complex** (object) values that cannot travel in a URL. `addItem` reads the value from the body (plus an optional `side` `left`/`right`); `moveItem` reads `position` from the body.

### Error codes

| Code | When |
|---|---|
| `400 Bad Request` | the targeted property is not declared in the model's `AQL::ARRAYS` |
| `404 Not Found` | the owner document does not exist; or (`hasItem`) the value is absent from the array |
| `422 Unprocessable Entity` | `moveItem` on a `sortedSet` field (sorting by value makes a manual position meaningless) |

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

Generates `POST /playlists/{id}/tracks` (addItem) and `DELETE|PATCH|GET /playlists/{id}/tracks/{value}` (removeItem / moveItem / hasItem).

> `arrayPurgeRef` (remove a value from **every** document that references it) is **not** exposed over HTTP: it is a cascade operation, triggered application-side through an `afterUpdate`/`afterDelete` listener (see [Embedded array fields](../db/arrays.md#propagating-a-change-to-parent-documents)).

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
- [Edge and join projection](../edges-joins-projection.md) — `Skin`, `AQL::REQUIRES`, *authorizer*.
- [Symfony Console commands](../commands.md) — parallel CLI exposition.
