# Search `?search=`

The `?search=` parameter is a model's **simple full-text search**: one (or several) term(s) `LIKE`-matched across a set of declared `searchable` fields. It is one of the three [search & filtering](search-and-filtering.md) levers — the broadest and the simplest to use from the client side.

## In a nutshell

```
?search=marc
```
sweeps every declared `searchable` field and keeps documents where **at least one** field **contains** `marc`:

```aql
(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true))
```

- **Case-insensitive** (`LIKE`'s third argument `true` is ArangoDB's *case-insensitive* mode): `marc` matches `Marc`, `MARC`, `Marco`…
- **`contains`**: the term is wrapped in `%` (`%marc%`), so it matches anywhere in the value.
- **OR everywhere**: a document matches as soon as one term appears in one field.

## Several terms

Terms are **comma-separated**. Each term is bound separately and tested against every field; the whole is combined with `OR`:

```
?search=marc,marco
```
```aql
(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true)
 || LIKE(doc.name,@search_1,true) || LIKE(doc.firstName,@search_1,true))
// @search_0 = "%marc%" , @search_1 = "%marco%"
```

> The semantics is an **OR** between terms ("contains marc OR marco"). To require several conditions at once (AND), use [`?filter=`](filter.md) instead.

## Model-side declaration

The swept fields are declared in the **`AQL::SEARCHABLE`** (= `'searchable'`) list at model construction:

```php
use oihana\arango\models\Documents ;
use oihana\arango\db\enums\AQL ;

$users = new Documents
([
    AQL::COLLECTION => 'users' ,
    AQL::SEARCHABLE => [ 'name' , 'firstName' , 'email' ] , // fields swept by ?search
]) ;
```

- With no `searchable` list (or an empty one), `?search` produces nothing (`null`) — the search is inert until at least one field is declared.
- Fields are **document-relative paths** (`name`, `address.city`…), interpolated as-is — they come from the **model declaration**, not the URL (no injection is possible through `?search`).

## Edge cases

| Input | Result |
|---|---|
| `?search=` (empty) | no fragment (`null`) — the search is ignored |
| `?search` absent | no fragment (`null`) |
| model without `searchable` | no fragment (`null`) |
| `?search=marc` | `(LIKE(doc.<f1>,@search_0,true) || LIKE(doc.<f2>,@search_0,true) || …)` |

Like the other levers, an inert `?search` never breaks the query: it simply adds no condition.

## Combining with filters and facets

`?search` stacks (logical **AND**) with `?filter` and `?facets` in the same request — it forms its own internal `OR` group, AND-ed to the rest:

```
?search=marc&filter={"key":"active","val":true}&facets={"role":"admin"}
// → (… LIKE …) && doc.active == @v && (… role facet …)
```

See [Search & filtering](search-and-filtering.md) for the full comparison table.

## Limits — when to move to ArangoSearch

`?search` is a **multi-field `LIKE`**: no relevance/scoring, no tokenization, no stemming or accent-insensitivity, no index-optimized prefix search. This is deliberate — it is the "good enough" everyday search.

For **real full-text** (analyzers, `BM25`/`TFIDF` scoring, tokenization, fuzzy), use an **ArangoSearch view**: see [ArangoSearch (views)](../clients/arangosearch.md). The two are complementary: `?search` for a simple search bar over a model, ArangoSearch for a full-blown search engine.

## See also

- [Search & filtering](search-and-filtering.md) — overview of the 3 levers.
- [Filters `?filter=`](filter.md) — for precise conditions (AND/OR/NOT, comparators, dates).
- [Facets `?facets=`](facets.md) — for multi-select and relations.
- [ArangoSearch (views)](../clients/arangosearch.md) — advanced full-text.
