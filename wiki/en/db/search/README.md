# Search `?search=`

The `?search=` parameter is a model's **simple full-text search**: one (or several) term(s) `LIKE`-matched across a set of declared `searchable` fields. It is one of the three [search & filtering](../search-and-filtering.md) levers — the broadest and the simplest to use from the client side.

## The whole search family at a glance

The same `?search=` parameter spans a whole range, from the simple bar to the federated engine — **with no URL change** on the client side. Pick by need:

| Level | Page | What for |
|---|---|---|
| **Default** | simple search (this page, below) | Multi-field `LIKE`, case-insensitive, zero config. Good enough every day. |
| Relevance | [View search (ArangoSearch)](overview.md) | Inverted index + `BM25` score, boosts, phrase, fuzzy. **The full-text.** |
| Per field | [Per-field options](per-field-options.md) | Boost, fuzzy, Analyzer, autocomplete, language, phrase, permissions — field by field. |
| Arrays | [Object-array fields](array-fields.md) | A sub-field of an array of objects (`contactPoints[*].email`). |
| Multi-collection | [Federated search](federated.md) | A single bar over several collections, ranked by relevance. |
| Text preparation | [Analyzers](../analyzers.md) | Tokenization, stemming, accents, n-grams — the brick under the View. |

The rest of this page documents the **default level** (the `LIKE` sweep).

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

> The default semantics is an **OR** between terms ("contains marc OR marco"). To require **all the words** of a term in one same field (AND), see [`Search::OPERATOR`](#requiring-every-word-of-a-term-searchoperator) just below; to combine conditions over **different fields**, use [`?filter=`](../filter.md).

## Requiring every word of a term (`Search::OPERATOR`)

By default a multi-word term is matched **as a whole**: `?search=fourcade marc` binds `%fourcade marc%` and requires the exact run "fourcade marc" (adjacent, in order). To require instead that **each word** be found in the field, **regardless of order**, declare `Search::OPERATOR => Logic::AND` on the model:

```php
use oihana\arango\db\enums\Logic ;
use oihana\arango\models\enums\Search ;

AQL::SEARCHABLE  => [ 'name' , 'firstName' ] ,
Search::OPERATOR => Logic::AND , // every word must be found in the same field
```

`?search=fourcade marc` then generates, per field, a conjunction of substrings (one bind per word):

```aql
( (LIKE(doc.name,@search_0_0,true) && LIKE(doc.name,@search_0_1,true))
  || (LIKE(doc.firstName,@search_0_0,true) && LIKE(doc.firstName,@search_0_1,true)) )
// @search_0_0 = "%fourcade%" , @search_0_1 = "%marc%"
```

- Splitting happens on **whitespace**: "fourcade marc" and "marc fourcade" both keep the same record (order-free), where the default `%whole term%` would match only the exact order.
- **Fields** stay OR-ed (each word in the *same* field), and **comma-separated terms** stay OR-ed (`fourcade marc,dupont` = both words OR "dupont").
- Default `Logic::OR`: the whole-term substring of today, **unchanged** AQL output (backward-compatible).

It is the `LIKE`-sweep counterpart of the [View search `Search::OPERATOR`](per-field-options.md#combining-a-terms-words-searchoperator) — same intent (tighten a term's words within a field), mechanism adapted (`LIKE` substring instead of `IN TOKENS`). Here the operator is **model-wide** (the `LIKE` sweep has no per-field declaration).

Words are split on **whitespace** and, **by default, the hyphen** — « Jean-Marc » behaves like « Jean Marc ». `Search::SEPARATORS` (a model init key) declares the separator characters on top of whitespace, as a **string** (`"-./"`) or a **list** (`["-", ".", "/"]`); an **empty** value (`""`) splits on whitespace only (to keep a `REF-2024` code whole). Only active in `AND` mode.

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

## Permissions — gated fields

A searchable field can be **permission-gated**: the list stays homogeneous, and an array entry carries its name under `Search::KEY` plus the required subject(s) under `Search::REQUIRES` (a string or a list, OR):

```php
use oihana\arango\models\enums\Search ;

AQL::SEARCHABLE =>
[
    'name' ,                                                          // public
    [ Search::KEY => 'salary' , Search::REQUIRES => 'hr.salary:search' ] , // gated
] ,
```

The gated field is swept only when the request **authorizer** (the `Arango::AUTHORIZER` closure, injected by the controller, consulted by `isAuthorized()`) grants a subject — exactly like the [projection gating](../../projection.md) (`Field::REQUIRES`) and the [View search](per-field-options.md#search-permissions). With no authorizer the layer is disabled (fail-open). **If every searchable field is denied**, the search returns **nothing** (`FILTER false`) — it is never silently dropped (which would return everything).

## Edge cases

| Input | Result |
|---|---|
| `?search=` (empty) | no fragment (`null`) — the search is ignored |
| `?search` absent | no fragment (`null`) |
| model without `searchable` | no fragment (`null`) |
| `?search=marc` | `(LIKE(doc.<f1>,@search_0,true) || LIKE(doc.<f2>,@search_0,true) || …)` |
| `?search=marc`, every gated field denied | `false` (0 results) — see [Permissions](#permissions--gated-fields) |

Like the other levers, an inert `?search` never breaks the query: it simply adds no condition.

## Combining with filters and facets

`?search` stacks (logical **AND**) with `?filter` and `?facets` in the same request — it forms its own internal `OR` group, AND-ed to the rest:

```
?search=marc&filter={"key":"active","val":true}&facets={"role":"admin"}
// → (… LIKE …) && doc.active == @v && (… role facet …)
```

See [Search & filtering](../search-and-filtering.md) for the full comparison table.

## Limits — when to move to ArangoSearch

`?search` is a **multi-field `LIKE`**: no relevance/scoring, no tokenization, no stemming or accent-insensitivity, no index-optimized prefix search. This is deliberate — it is the "good enough" everyday search.

For **real full-text** (analyzers, `BM25`/`TFIDF` scoring, tokenization, fuzzy), declare an **ArangoSearch View on the model** (the `AQL::VIEW` block): the same `?search=` parameter then switches to an index-accelerated, relevance-ranked search — see [View search (ArangoSearch)](overview.md). The two are complementary: `?search` over `searchable` for a simple search bar, the View declaration for a full-blown search engine — with no URL change between them.

## See also

- [Search & filtering](../search-and-filtering.md) — overview of the 3 levers.
- [Filters `?filter=`](../filter.md) — for precise conditions (AND/OR/NOT, comparators, dates).
- [Facets `?facets=`](../facets.md) — for multi-select and relations.
- [ArangoSearch (views)](../../clients/arangosearch.md) — advanced full-text.
