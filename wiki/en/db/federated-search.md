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

## See also

- [`search-alias` views](../clients/arangosearch.md) — the substrate (one inverted index per collection, federatable).
- [View search (ArangoSearch)](search-views.md) — the per-model scored search.
- [`aqlScoredSearch()`](../aql/aql-operations.md) — the scored-query builder reused by stage 1.
