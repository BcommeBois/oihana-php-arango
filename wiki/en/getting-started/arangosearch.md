# Understanding ArangoSearch

ArangoSearch is ArangoDB's **built-in search engine** — the equivalent of a small Elasticsearch living inside your database, with no extra server. This page explains the concepts (Analyzer → View → `SEARCH` → scoring), what they make possible, and how every layer of this library maps onto them. It is the recommended starting point before the [recipe](../db/search/overview.md) and [reference](../aql/aql-functions-search.md) pages.

## Why — the four ceilings of `LIKE`

The classic [`?search=`](../db/search/README.md) sweep (`LIKE '%term%'` over a few fields) is simple and healthy, but it hits four glass ceilings:

1. **No relevance.** `LIKE` answers yes or no. If 200 documents contain *wood*, they come back sorted by name — not from best to worst match.
2. **No tolerance.** `behuard` does not find *Béhuard* (accent), `Behuart` neither (typo).
3. **No notion of words.** Searching `sawmill river` requires that exact substring somewhere; two words present in two fields, or out of order, never match.
4. **No index.** `LIKE '%…%'` cannot be indexed: every search reads the whole collection. Invisible at 5 000 documents, painful at 500 000.

ArangoSearch removes all four — by moving the intelligence **to indexing time**.

## The four building blocks

### The Analyzer — the text grinder

An Analyzer turns text into **normalized tokens**, at indexing time *and* at query time, so both sides always meet on the same ground:

```
"The Quick Foxes!"   --text_en-->   [ quick, fox ]
```

Lowercase, accents stripped, stop words removed, and *stemming* (*foxes* → `fox`). That is what makes search linguistic: `fox` finds *Foxes*. ArangoDB ships text Analyzers for ~10 languages (`text_en`, `text_fr`, …); you can also build your own. It is all covered in the dedicated **[Analyzers](../db/analyzers.md)** page (types, features, creating a custom analyzer).

> An Analyzer is **frozen at indexing time**. Overriding it at query time alone is useless (you would search English-stemmed tokens in a French-stemmed index) — what you change is the *field* you search, and the right Analyzer follows the field.

### The View — the search index

A View is a *virtual collection* that indexes one or **several** collections, field by field, each through an Analyzer:

```
View "thingsView"
 ├── places   : name → text_fr, description → text_fr
 └── products : name → text_fr
```

Under the hood it is an **inverted index**: token → documents (the opposite of a collection). You query it like a collection (`FOR doc IN thingsView`) and it returns documents from any linked collection — the native answer to multi-collection search.

### The `SEARCH` operation — querying through the index

`SEARCH` is the AQL operation that queries a View **through** its inverted index — unlike `FILTER`, which post-processes. Its expression is where the search functions live:

| Function | What for | Typical use |
|---|---|---|
| `PHRASE` | tokens **adjacent, in order** | "exact expression" matching |
| `STARTS_WITH` | prefix(es) | autocomplete |
| `LEVENSHTEIN_MATCH` | edit distance | typo tolerance |
| `NGRAM_MATCH` / `MINHASH_MATCH` | similarity | near-duplicates, fuzzy codes (custom Analyzers) |
| `MIN_MATCH` | at least *n* sub-expressions | "2 words out of 3 suffice" |
| `EXISTS` / `IN_RANGE` | presence / range | structured checks, index-accelerated |
| `BOOST` / `ANALYZER` | context | weighting, Analyzer scoping |

### The scorers — relevance

`BM25(doc)` (recommended) and `TFIDF(doc)` give every match a **score** (term frequency, rarity, text length, boosts). `SORT BM25(doc) DESC` puts the best matches first — the piece with **no equivalent** in a `LIKE` world. **BM25 is the modern standard**: it refines TF-IDF by *saturating* repetition (a word repeated 100 times is not worth 100×) and by correcting for text length. Keep it by default — `TFIDF` is exposed only for completeness.

```aql
FOR doc IN placesView
  SEARCH ANALYZER( BOOST(PHRASE(doc.name, @q), 3) OR doc.description IN TOKENS(@q, "text_fr") , "text_fr")
  SORT BM25(doc) DESC
  LIMIT 20
  RETURN doc
```

One query: accent/case insensitivity, word-based matching, name weighing 3× the description, best matches first, index-accelerated.

## What becomes possible

| Need | Ingredients | In this library |
|---|---|---|
| Search bar with relevance | `TOKENS` match + `BM25` | [`AQL::VIEW` block](../db/search/overview.md), automatic |
| Exact-phrase priority | `PHRASE` + `BOOST` | `Search::PHRASE => true` (global or per-field) |
| Typo tolerance | `LEVENSHTEIN_MATCH` | `Search::FUZZY => 1` (global or per-field) |
| Field weighting | `BOOST` | `Search::FIELDS => ['name' => 3]` |
| Autocomplete | `STARTS_WITH` (array of prefixes) | [`startsWith()`](../aql/aql-functions-strings.md) helper |
| Custom scored query | the full grammar | [`aqlScoredSearch()`](../aql/aql-operations.md) builder |
| Localized fields (i18n) | per-field sub-path + Analyzer + locale, `?lang=`-driven search | `'intro.en' => [Search::ANALYZER => 'text_en', Search::LANG => 'en']` |
| Federated multi-collection search | one View, several collections | planned (dedicated read-only model) |

## How a View lives, server-side

- **A first-class entity** — like a collection: visible in the web UI, managed via `/_api/view`, created once.
- **It synchronizes itself** — inserts/updates/deletes in linked collections propagate to the index in the background (`commitIntervalMsec`, ~1 s): *eventual consistency*. A fresh document is readable instantly, searchable ~1 s later. Internal segment consolidation and cleanup are automatic — you never empty or rebuild anything.
- **Initial indexing** — creating a View over an existing collection indexes it in the background; the View is queryable immediately but incomplete until done.
- **Cost** — it is an index: disk and RAM proportional to the indexed fields. Declare the useful fields; avoid `includeAllFields` on large documents.
- **Dump/restore** — `arangodump` saves View *definitions* by default (the inverted index is rebuilt on restore, in the background). Custom Analyzers live in the `_analyzers` **system** collection, excluded unless `--include-system-collections` — built-in ones (`text_fr`, …) are always available.

## The library, layer by layer

| Layer | What it gives you | Page |
|---|---|---|
| Function helpers (`db/functions/search/`) | one PHP helper per ArangoSearch function (`phrase`, `boost`, `bm25`, …) | [ArangoSearch functions](../aql/aql-functions-search.md) |
| `aqlSearch()` operation | the `SEARCH … OPTIONS { … }` clause with Analyzer wrap | [AQL operations](../aql/aql-operations.md) |
| `aqlScoredSearch()` builder | the complete relevance-ranked query, standalone | [AQL operations](../aql/aql-operations.md) |
| Model `AQL::VIEW` block | `?search=` switches to the View, auto-provisioning, `score` sort key, totals & facet counts in sync | [View search](../db/search/overview.md) |
| Views & Analyzers clients | create/update/drop Views, custom Analyzers | [ArangoSearch clients](../clients/arangosearch.md) |

## Good practices and pitfalls

- **Never `==` with a text Analyzer** — indexed tokens are stemmed, a raw literal is not: `ANALYZER(doc.name == "bois", "text_fr")` matches nothing. Use `doc.name IN TOKENS(@q, "text_fr")` (both sides analyzed) or `PHRASE` — the model grammar does this for you.
- **Analyzer features matter** — `BM25` needs `frequency` (+ `norm` for length normalization), `PHRASE` needs `position` + `frequency`. Built-in text Analyzers have them; custom ones must declare them.
- **`FILTER` after `SEARCH` is legal but unaccelerated** — fine when the `SEARCH` already narrowed the set; prefer `SEARCH`-able predicates (`EXISTS`, `IN_RANGE`) for heavy structured conditions.
- **Declaration drift** — the model's View provisioning is create-if-missing: changing the `AQL::VIEW` block does **not** update an existing View (a new field is silently not indexed). Detect the gap with `$model->viewDiff()` and repair it with `$model->viewSync()` — or from the CLI: `command:arangodb views --diff` / `--sync` ([doc](../commands/arangodb.md#views--arangosearch-view-management)).

## Planned evolutions

- **Finer i18n search** beyond the already-available `?lang=` ([per-field Analyzer](../db/search/per-field-options.md#per-field-analyzer) and [localized search `?lang=`](../db/search/per-field-options.md#localized-search-lang)): for instance one field indexed with several Analyzers.
- **Federated multi-collection search** — one View over several collections, exposed by a dedicated read-only model/controller/route triple.

Permission-scoped search is now [available](../db/search/per-field-options.md#search-permissions).

> The View management command, long listed here, has shipped: see the [`views` action of `command:arangodb`](../commands/arangodb.md#views--arangosearch-view-management) (list, `--diff`, `--sync`, `--drop`).

## See also

- [View search (ArangoSearch)](../db/search/overview.md) — the model recipe (`AQL::VIEW`, URLs, JSON responses).
- [ArangoSearch functions](../aql/aql-functions-search.md) — the helper reference.
- [ArangoSearch clients](../clients/arangosearch.md) — Views and Analyzers management.
- [Search `?search=`](../db/search/README.md) — the simple `LIKE` sweep, still the right tool for small models.
- [Official documentation — ArangoSearch](https://docs.arangodb.com/stable/index-and-search/arangosearch/).
