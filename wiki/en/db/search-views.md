# View search (ArangoSearch) — relevance-ranked `?search=`

Declare an **ArangoSearch View** on a `Documents` model (the `AQL::VIEW` block) and the [`?search=`](search.md) parameter switches, automatically and **without any URL change**, from the simple `LIKE` sweep to an **index-accelerated, relevance-ranked** search: linguistic matching (tokenization, stemming, accents), per-field boosts, exact-phrase bonus, typo tolerance, and a `BM25` score ordering the best matches first.

> New to ArangoSearch (Analyzers, Views, scoring)? Start with the primer on the [ArangoSearch functions](../aql/aql-functions-search.md) page and the [ArangoSearch clients](../clients/arangosearch.md) guide.

## Model declaration

```php
use oihana\arango\models\Documents ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Search ;

$places = new Documents( $container ,
[
    AQL::COLLECTION => 'places' ,
    AQL::VIEW =>
    [
        Search::NAME     => 'placesView' ,   // the View name (required)
        Search::ANALYZER => 'text_fr' ,      // Analyzer of the searched fields
        Search::FIELDS   =>
        [
            'name'        => 3 ,             // field => boost (name weighs 3×)
            'description' => 1 ,
        ] ,
        Search::PHRASE   => true ,           // exact-phrase bonus (boost ×2)
        Search::FUZZY    => 1 ,              // Levenshtein tolerance (0 = off)
    ] ,
]) ;
```

| Key | Type | Role |
|---|---|---|
| `Search::NAME` | `string` | **Required** — the View name. Without it the block is inert and `?search=` stays the `LIKE` sweep. |
| `Search::ANALYZER` | `string` | Analyzer used to index **and** query the fields (default `identity` — declare a text Analyzer for linguistic search). |
| `Search::FIELDS` | `array` | `field => boost` map (or `field => [ Search::BOOST => n ]`). Dotted paths supported. Falls back to `AQL::SEARCHABLE` (boost 1). |
| `Search::PHRASE` | `bool` | Adds an exact-phrase bonus: a `PHRASE()` match weighs `boost × 2`. |
| `Search::FUZZY` | `int` | Adds typo tolerance: `LEVENSHTEIN_MATCH` with this maximum edit distance. |

**Provisioning is automatic**: like the collection and its `AQL::INDEXES`, the View is lazily created at model initialization when it does not exist (searched fields linked with the declared Analyzer). An existing View is **never altered** — after changing the declaration, update the View manually (`$db->view('placesView')->updateProperties([...])`) or drop it and let the model recreate it.

## URLs and behavior

```
GET /places?search=scierie
```

generates (terms bound — user input never reaches the AQL text):

```aql
FOR doc IN placesView
  SEARCH ANALYZER(
       BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3)
    OR BOOST(PHRASE(doc.name, @search_0), 6)
    OR LEVENSHTEIN_MATCH(doc.name, @search_0, 1)
    OR doc.description IN TOKENS(@search_0, "text_fr")
    OR BOOST(PHRASE(doc.description, @search_0), 2)
    OR LEVENSHTEIN_MATCH(doc.description, @search_0, 1)
  , "text_fr")
  SORT BM25(doc) DESC
  LIMIT 0, 50
  RETURN { ... }
```

The `?search=` contract is unchanged: comma-separated terms, **OR** everywhere — only the engine differs. And the rest of the pipeline ([`?filter=`](filter.md), [`?facets=`](facets.md), `?limit`/`?offset`, skins, projections) keeps working as before: filters apply **after** the `SEARCH` as a post-processing `FILTER`.

### Relevance and `?sort=`

An active search exposes the synthetic **`score`** sort key (the relevance counterpart of [`distance`](search-and-filtering.md#distance-sorting-near) for `?near=`):

| Request | Order |
|---|---|
| `?search=scierie` | `score` DESC (default — most relevant first, overrides `SORT_DEFAULT`) |
| `?search=scierie&sort=-score,name` | relevance, then name |
| `?search=scierie&sort=name` | name only — relevance **not** appended (explicit `?sort` decides) |
| `?sort=score` without `?search=` | dropped (no active search) |

### Responses

The JSON envelope is **identical** to a classic list (the standard `status` / `url` / `count` / `total` / `result` success envelope of the controllers) — only the order (and the matching quality) changes:

```json
{
  "status": "success",
  "url": "https://api.example.org/places?search=bois",
  "count": 2,
  "total": 2,
  "result":
  [
    { "name": "Atelier du bois" , "description": "menuiserie fine" } ,
    { "name": "Scierie de la Loire" , "description": "le bois de chêne et de sapin" }
  ]
}
```

`total`, [`?count`](../models.md) **and** [`?facetCounts=`](facets.md#facet-counts-facetcounts) all follow the same `SEARCH` — the list, the totals and the facet buckets always agree on the matched set.

## Recipes

**Search bar with relevance** — the declaration above; nothing else. `?search=scierie` returns the best matches first, tolerates one typo (`scierei`), and survives accents/plurals via the Analyzer.

**Autocomplete-ish prefix bias** — keep `Search::PHRASE => true`: while typing whole words, exact phrases rank on top.

**Localized sub-field** — fields are paths: `Search::FIELDS => [ 'description.fr' => 1 ]` searches the French side of an i18n `{ "fr": …, "en": … }` attribute (per-field Analyzers and `?lang=`-driven selection are planned evolutions).

**Forcing a classic order** — relevance is only the *default*: `?search=bois&sort=name` (or any `?sort=`) takes over entirely, exactly as before.

**Opting out** — remove the `AQL::VIEW` block (or its `Search::NAME`): `?search=` instantly falls back to the historical `LIKE` sweep over `AQL::SEARCHABLE`. No URL, controller or route changes either way.

## Good to know

- **Eventual consistency** — a freshly inserted document becomes searchable in the View after ~1 s (`commitIntervalMsec`). Lists without `?search=` are not affected.
- **Scoring requirements** — the `BM25` score needs the Analyzer `frequency` feature (built-in text Analyzers have it); `PHRASE` needs `position` + `frequency`.
- **The search is bound** — terms travel as `@search_N` bind variables; field names come from the model declaration, never from the URL.

## See also

- [Search `?search=`](search.md) — the `LIKE` sweep (models without a View).
- [Search & filtering](search-and-filtering.md) — overview of the levers.
- [ArangoSearch functions](../aql/aql-functions-search.md) — the underlying `SEARCH` helpers and a primer.
- [`aqlScoredSearch()`](../aql/aql-operations.md) — the standalone scored-query builder.
- [ArangoSearch clients](../clients/arangosearch.md) — Views and Analyzers management.
