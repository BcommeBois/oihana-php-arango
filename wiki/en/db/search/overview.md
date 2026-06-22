# View search (ArangoSearch) — relevance-ranked `?search=`

Declare an **ArangoSearch View** on a `Documents` model (the `AQL::VIEW` block) and the [`?search=`](../search.md) parameter switches, automatically and **without any URL change**, from the simple `LIKE` sweep to an **index-accelerated, relevance-ranked** search: linguistic matching (tokenization, stemming, accents), per-field boosts, exact-phrase bonus, typo tolerance, and a `BM25` score ordering the best matches first.

> New to ArangoSearch (Analyzers, Views, scoring)? Start with the [Understanding ArangoSearch](../../getting-started/arangosearch.md) primer.

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
| `Search::ANALYZER` | `string` | Analyzer used to index **and** query the fields (default `identity` — declare a text Analyzer for linguistic search). Overridable per field — see [Per-field options](per-field-options.md). |
| `Search::FIELDS` | `array` | `field => boost` map (or `field => [ Search::BOOST => n, Search::FUZZY => d ]` to carry per-field options). Dotted paths supported, as well as **sub-fields of arrays of objects** via `[*]` ([see](array-fields.md)). Falls back to `AQL::SEARCHABLE` (boost 1). |
| `Search::PHRASE` | `bool` | Adds an exact-phrase bonus: a `PHRASE()` match weighs `boost × 2`. |
| `Search::FUZZY` | `int` | View-level typo tolerance: `LEVENSHTEIN_MATCH` with this maximum edit distance (valid value `0`–`4`, `0` = off). Overridable per field — see [Per-field options](per-field-options.md). |

> **Configure each field** — beyond the boost, each `Search::FIELDS` entry accepts **per-field** options (typo tolerance, Analyzer, multiple Analyzers/autocomplete, language, exact phrase, permissions). See the dedicated [Per-field options](per-field-options.md) page. To index a sub-field of an array of objects, see [Object-array fields](array-fields.md).

## Automatic provisioning

**Provisioning is automatic**: like the collection and its `AQL::INDEXES`, the View is lazily created at model initialization when it does not exist (searched fields linked with the declared Analyzer). An existing View is **never altered automatically** — after changing the declaration, inspect and resynchronize explicitly: `$model->viewDiff()` detects the gap, `$model->viewSync()` repairs it through `updateProperties()` (the View stays queryable while re-indexing), and the [`views` action of the `arangodb` command](../../commands/arangodb.md#views--arangosearch-view-management) does the same from the CLI (`--diff` / `--sync`), ready for deployment scripts:

```bash
# after an AQL::VIEW declaration change: review the gap, then resynchronize
composer arango:views -- --diff              # read-only: lists Views to create / drifted
composer arango:views -- --sync              # creates the missing ones + resyncs every drifted one
composer arango:views -- --sync=placesView   # targeted (several names, comma-separated)
```

> Equivalent long form: `php bin/console.php command:arangodb views --sync`. `--sync` favors `updateProperties()` (soft update) over a drop + recreate.

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

The `?search=` contract is unchanged: comma-separated terms, **OR** everywhere — only the engine differs. And the rest of the pipeline ([`?filter=`](../filter.md), [`?facets=`](../facets.md), `?limit`/`?offset`, skins, projections) keeps working as before: filters apply **after** the `SEARCH` as a post-processing `FILTER`.

### Relevance and `?sort=`

An active search exposes the synthetic **`score`** sort key (the relevance counterpart of [`distance`](../search-and-filtering.md#distance-sorting-near) for `?near=`):

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

`total`, [`?count`](../../models.md) **and** [`?facetCounts=`](../facets.md#facet-counts-facetcounts) all follow the same `SEARCH` — the list, the totals and the facet buckets always agree on the matched set.

## Recipes

**Search bar with relevance** — the declaration above; nothing else. `?search=scierie` returns the best matches first, tolerates one typo (`scierei`), and survives accents/plurals via the Analyzer.

**Autocomplete-ish prefix bias** — keep `Search::PHRASE => true`: while typing whole words, exact phrases rank on top. For true fragment-based autocomplete, see [multiple Analyzers per field](per-field-options.md#multiple-analyzers-per-field-autocomplete).

**Localized sub-field** — fields are paths: `Search::FIELDS => [ 'description.fr' => 1 ]` searches the French side of an i18n `{ "fr": …, "en": … }` attribute. To go further, a [per-field Analyzer](per-field-options.md#per-field-analyzer) (French/English) and a [`?lang=`-driven selection](per-field-options.md#localized-search-lang) are available.

**Object-array sub-field** — `Search::FIELDS => [ 'contactPoints[*].email' => 1 ]` makes the `email` of **every** element of the `contactPoints` array searchable — see [Object-array fields](array-fields.md).

**Forcing a classic order** — relevance is only the *default*: `?search=bois&sort=name` (or any `?sort=`) takes over entirely, exactly as before.

**Opting out** — remove the `AQL::VIEW` block (or its `Search::NAME`): `?search=` instantly falls back to the historical `LIKE` sweep over `AQL::SEARCHABLE`. No URL, controller or route changes either way.

## Good to know

- **Eventual consistency** — a freshly inserted document becomes searchable in the View after ~1 s (`commitIntervalMsec`). Lists without `?search=` are not affected.
- **Scoring requirements** — the `BM25` score needs the Analyzer `frequency` feature (built-in text Analyzers have it); `PHRASE` needs `position` + `frequency`.
- **The search is bound** — terms travel as `@search_N` bind variables; field names come from the model declaration, never from the URL.
- **Analyzers must exist first** — a View references its Analyzers by **name**; it does not create them. Built-ins (`text_fr`, `text_en`, `identity`…) are always present. A **custom** Analyzer must be declared in the `analyzers` registry and created on the server (`composer arango:analyzers -- --sync` or `composer arango:doctor -- --apply`) **before** the View — its definition (type, properties, features) cannot be inferred from the name alone. Otherwise the View is `INVALID` and lazy creation fails silently (the search then errors at runtime). Diagnose with `composer arango:views -- --diff` or `composer arango:doctor`. See [Analyzers](../analyzers.md).

## See also

- [Per-field options](per-field-options.md) — configure each field (boost, fuzzy, Analyzer, autocomplete, language, phrase, permissions).
- [Object-array fields](array-fields.md) — `contactPoints[*].email`.
- [Analyzers](../analyzers.md) — catalogue and creating a custom Analyzer.
- [Search `?search=`](../search.md) — the `LIKE` sweep (models without a View).
- [Search & filtering](../search-and-filtering.md) — overview of the levers.
- [ArangoSearch functions](../../aql/aql-functions-search.md) — the underlying `SEARCH` helpers.
- [`aqlScoredSearch()`](../../aql/aql-operations.md) — the standalone scored-query builder.
- [ArangoSearch clients](../../clients/arangosearch.md) — Views and Analyzers management.
