# Federated multi-collection search — `FederatedSearch`

A **single search bar** that searches **several collections at once** (customers, products, sellers, places…) and returns **one list ranked by relevance** — the user never has to choose where to look.

Example: the user types "dupont" and sees, mixed and ranked from most to least relevant, the **customer** "Dupont SARL", the **product** "Colle Dupont", the **seller** "Jean Dupont" and the **place** "Entrepôt Dupont".

## The problem, and how it is solved

There are **two distinct difficulties**:

1. **Searching several collections at once** — the mechanics, solved by the [`search-alias`](../clients/arangosearch.md) substrate: a view aggregating **one inverted index per collection**, queried in a single request.
2. **Rebuilding results of different shapes** — the heart of it. A customer, a product and a place do **not** share the same fields, linked data, display rules or permissions. They cannot all be rendered the same way: each result must be **rebuilt with its own model's logic**.

The approach is the **librarian** one: first you get a **ranked list of call numbers** (not the books), then each book is fetched **at its own shelf**, with its full record. In two stages:

- **Stage 1 — find**: one search over the `search-alias` view returns, for every match, **its source collection, its key and its score** (BM25), ranked and **paginated**. Not the full documents.
- **Stage 2 — rebuild**: the keys are grouped by collection and **each collection is rebuilt in a single call** to its model (`list()` with a `_key IN […]` filter), reusing its whole pipeline (fields, joins, skins, permissions). The documents are then merged back **in score order**.

The key benefit: the **machinery already written** for each model is **reused** instead of being reinvented in one unmanageable mega-query.

## Configuration

`FederatedSearch` is a standalone, container-aware service — not a model (it owns no collection).

```php
use oihana\arango\search\FederatedSearch ;
use oihana\arango\search\enums\FederatedSearchParam ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\enums\Search ;

$engine = new FederatedSearch( $container ,
[
    // the search-alias view to query
    FederatedSearchParam::VIEW => 'global_search' ,

    // what to search (fields + analyzer), applied uniformly
    FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'name' , 'label' ] , Search::ANALYZER => 'text_fr' ] ,

    // the directory : which model rebuilds which collection
    FederatedSearchParam::MODELS =>
    [
        'customers' => 'model.customers' ,
        'products'  => 'model.products' ,
        'sellers'   => 'model.sellers' ,
    ] ,

    // (optional) the permission each collection demands — see § Permissions
    FederatedSearchParam::REQUIRES =>
    [
        'customers' => 'customers:list' ,
        'sellers'   => 'sellers:list' ,
        // 'products' is not listed → public
    ] ,

    // (optional) the default rebuild skin (default: Skin::DEFAULT)
    FederatedSearchParam::SKIN => 'search' ,

    // the database (an ArangoDB instance or its container id)
    Arango::DATABASE => $arango ,
]) ;
```

### Polymorphic collections — routing by `additionalType`

Sometimes a single collection holds documents of **several types**, each rebuilt
by a different model — e.g. `organizations` served by a `Customer`, a `Provider`
and a `Subsidiary` model, with no generic one. A `MODELS` value can then be a
**composite** spec instead of a plain service-id, routing each match by a
discriminator field (`additionalType` by default):

```php
FederatedSearchParam::MODELS =>
[
    'products'      => 'model.products' , // direct : one model for the whole collection

    'organizations' =>                    // composite : routed by type
    [
        FederatedSearchParam::DISCRIMINATOR => 'additionalType' , // optional (this is the default)
        FederatedSearchParam::MAP =>
        [
            'https://schema.org/Customer'   => 'model.customers' ,
            'https://schema.org/Provider'   => 'model.providers' ,
            'https://schema.org/Subsidiary' => 'model.subsidiaries' ,
        ] ,
        FederatedSearchParam::FALLBACK => 'model.organizations' , // optional ; omitted → an unmapped type is dropped
    ] ,
]
```

At rebuild time the engine reads each matched key's discriminator in **one
lightweight lookup** (`FOR d IN organizations FILTER d._key IN @keys RETURN { _key, additionalType }`)
— no inverted-index `storedValues`, and no change to the search itself — buckets
the keys by resolved model, and rebuilds each bucket through its own model,
preserving the score order and the total.

- **Priority** — a document may carry **several** types (`additionalType` as an
  array); the mapping is walked in **declaration order**, so the first listed type
  the document has wins, deterministically, regardless of the document's own order.
- **Fallback** — an unmapped type uses `FALLBACK` when present, otherwise the match
  is dropped (it never reaches a wrong model).
- **Backward-compatible** — a direct `collection => 'model.id'` entry is unchanged.
- **Permissions** stay at the **collection** level (a per-`additionalType` gate is
  not yet supported — see § Permissions).

## Running a search

```php
$results = $engine->search(
[
    Arango::SEARCH => 'dupont' ,   // the term (bound, never inlined into the query)
    Arango::LIMIT  => 20 ,         // page size (default: 25)
    Arango::OFFSET => 0 ,          // page offset
    Arango::SKIN   => 'search' ,   // (optional) overrides the engine skin
] ) ;

$total = $engine->foundRows() ;    // total number of matches, for "X results, page Y"
```

Each result is a **wrapper** separating the provenance, the score and the rebuilt document:

```php
[
  [ 'collection' => 'customers' , 'score' => 9.1 , 'document' => /* the rebuilt customer */ ] ,
  [ 'collection' => 'products'  , 'score' => 7.4 , 'document' => /* the rebuilt product  */ ] ,
  [ 'collection' => 'customers' , 'score' => 5.2 , 'document' => /* another customer     */ ] ,
]
```

The documents have **different shapes** from one collection to another — by design: each is rebuilt by its own model.

### Pagination and total

**Pagination happens in stage 1**: the `LIMIT` is applied **once**, on the global ranking across all collections. Stage 2 therefore rebuilds **only the requested page** — never piles of results. The **total** (before the `LIMIT`) is computed at the same time (the `fullCount` option) and exposed by `foundRows()`.

### Skin

The skin selects **which fields** each model returns for a search result. Resolution, by priority: the request `?skin=` (`Arango::SKIN`) → the engine-configured skin (`FederatedSearchParam::SKIN`) → `Skin::DEFAULT`. The same skin name is passed to every model, which decides what *its* skin projects. For dedicated result cards, declare a `Skin::SEARCH` skin on the relevant fields of your models and configure the engine with it.

## Permissions — a per-collection gate

Not everyone may see every collection: some are sensitive (customers, sellers), others public (products, places). `FederatedSearch` adds a **per-collection gate**: before including a collection, it checks the user is allowed to search it. Otherwise the **whole collection is excluded** — its documents never appear, are not rebuilt, and are **not counted** in the total.

The gate is applied **at search time** (via `OPTIONS { collections }` on the view), so pagination and total stay correct.

### Declare the permission each collection demands

Each collection declares the **permission `subject`(s)** it requires — exactly like `Field::REQUIRES` for a field. These are **your** subjects (from your seeds / your routes), not a convention imposed by the engine:

```php
FederatedSearchParam::REQUIRES =>
[
    'customers' => 'customers:list' ,                     // a single subject
    'users'     => [ 'users:list' , 'users:admin' ] ,     // a list = OR (either one is enough)
    // a collection absent from this map is PUBLIC: searchable by everyone
]
```

### Provide the authorizer

Each request carries an **authorizer**: a `fn(string $subject): bool` deciding whether the current user holds a given subject. It is the same mechanism used everywhere in the library (`isAuthorized()` / `Arango::AUTHORIZER`) — wire it to your enforcer (Casbin, etc.).

```php
// a sales rep : may list customers, not sellers
$authorizer = fn( string $subject ) : bool => in_array( $subject , [ 'customers:list' ] , true ) ;

$results = $engine->search(
[
    Arango::SEARCH     => 'dupont' ,
    Arango::AUTHORIZER => $authorizer ,
] ) ;
```

What happens, collection by collection:

```
isAuthorized( 'customers:list' )         → yes  → keep customers
products requires nothing (public)       →      → keep products
isAuthorized( 'sellers:list' )           → no   → drop sellers

allowed collections = [ customers , products ]
→ the search runs ONLY over those collections ; sellers never appear.
```

An **anonymous visitor** (same data, different authorizer):

```php
$authorizer = fn( string $s ) => in_array( $s , [ 'products:list' ] , true ) ;
// → sees only products (and any public collection). No customers, no sellers.
```

### Rules

- **A collection without a declared `REQUIRES`** → public, searchable by everyone.
- **No authorizer provided** → everything is allowed (*fail-open*, consistent with the rest of the library).
- **A list of subjects** → **OR** semantics (one is enough).
- **No collection allowed** → empty result, total 0.
- **Field-level permissions** (a given field hidden from a given user) stay enforced **by each model** at rebuild time — this gate only handles the "whole collection" level.

## Exposing it over HTTP

The engine is usable as-is from PHP. To put it behind **a URL**, the library ships a **read-only triplet** mirroring the documents one (`route → controller → model`), except the controller holds a `FederatedSearch` instead of a single-collection model:

- [`FederatedSearchController`](../../../src/oihana/arango/controllers/FederatedSearchController.php) — the **HTTP plug**: it turns the request into the engine `$init`, wires permissions, runs the engine and renders the JSON. A **single** read-only action, `search()`.
- [`SearchRoute`](../../../src/oihana/arango/routes/SearchRoute.php) — declares the `GET` route bound to the `search` action.

### The request

```
GET /search?search=dupont&limit=25&offset=0&skin=compact
```

```json
{
  "status": "success",
  "url": "/search?search=dupont&limit=25&offset=0&skin=compact",
  "count": 3,
  "total": 47,
  "result": [
    { "collection": "customers", "score": 4.2, "document": { "_key": "…", "name": "Dupont SARL" } },
    { "collection": "products",  "score": 3.1, "document": { "_key": "…", "name": "Colle Dupont" } },
    { "collection": "sellers",   "score": 2.8, "document": { "_key": "…", "name": "Jean Dupont" } }
  ]
}
```

`search` / `limit` / `offset` / `skin` are read from the query string; `total` (from `foundRows()`) rides along with the page for "X results, page Y".

### DI wiring

The engine is declared **once** as a service, then referenced by its id in the controller, itself referenced by its id in the route:

```php
use oihana\arango\controllers\FederatedSearchController ;
use oihana\arango\routes\SearchRoute ;
use oihana\arango\search\FederatedSearch ;
use oihana\routes\Route ;

// definitions/services.php — the engine (see § Configuration)
'search.engine' => fn( Container $c ) => new FederatedSearch( $c, [ /* VIEW, SEARCHABLE, MODELS, REQUIRES, DATABASE … */ ] ) ,

// definitions/controllers.php
'search.controller' => fn( Container $c ) => new FederatedSearchController( $c,
[
    FederatedSearchController::ENGINE => 'search.engine' , // engine service id (or an instance)
]) ,

// definitions/routes.php
'search.route' => fn( Container $c ) => new SearchRoute( $c,
[
    Route::CONTROLLER_ID => 'search.controller' ,
    Route::ROUTE         => '/search' ,
]) ,
```

### What about permissions?

**Nothing new to wire.** Exactly like `DocumentsController`, the controller resolves the enforcer (Casbin…) and the subject resolver from the container, builds a request-scoped authorizer `fn(string $subject): bool` and **poses it under `Arango::AUTHORIZER`** in the engine `$init`. The engine then applies its per-collection gate (see § Permissions) on its own. With no enforcer (tests, CLI, auth disabled) the authorizer is `null` and the gate falls open (*fail-open*) — behaviour unchanged. The query-param capability gating (right to search / to use a given skin) is reused verbatim from the documents controller foundation.

## See also

- [`search-alias` views](../clients/arangosearch.md) — the substrate (one inverted index per collection, federatable).
- [View search (ArangoSearch)](search/README.md) — the per-model scored search.
- [`aqlScoredSearch()`](../aql/aql-operations.md) — the scored-query builder reused by stage 1.
