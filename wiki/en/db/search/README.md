# View search (ArangoSearch)

Declare an **ArangoSearch View** on a `Documents` model (the `AQL::VIEW` block) and the [`?search=`](../search.md) parameter switches, **without any URL change**, from the simple `LIKE` sweep to an **index-accelerated, relevance-ranked** search (linguistic matching, per-field boosts, exact phrase, typo tolerance, autocomplete, `BM25` score).

> New to ArangoSearch (Analyzers, Views, scoring)? Start with the [Understanding ArangoSearch](../../getting-started/arangosearch.md) primer.

## Where to start

| Page | Contents |
|---|---|
| [Overview](overview.md) | Declaring the View, URL behavior, relevance and `?sort=`, provisioning, recipes, "good to know". **Start here.** |
| [Per-field options](per-field-options.md) | Configure **each** field: boost, typo tolerance, Analyzer, **multiple Analyzers (autocomplete)**, language (`?lang=`), exact phrase, permissions. |
| [Object-array fields](array-fields.md) | Index and search a sub-field of an array of objects (`contactPoints[*].email`). |
| [Analyzers](../analyzers.md) | The catalogue of Analyzers (built-in and buildable) and how to create a custom one. |

## See also

- [Search `?search=`](../search.md) — the `LIKE` sweep (models without a View).
- [Search & filtering](../search-and-filtering.md) — overview of the levers.
- [ArangoSearch functions](../../aql/aql-functions-search.md) — the underlying `SEARCH` helpers.
- [ArangoSearch clients](../../clients/arangosearch.md) — Views and Analyzers management.
