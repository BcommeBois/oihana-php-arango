# Search & filtering

Three URL parameters **narrow** the list returned by a `Documents` model: `?search=`, `?filter=` and `?facets=`. This page is the entry point: it explains **when to use which**, what they have **in common**, and how they **combine**. Each then has its own dedicated page.

| Parameter | What for | Page |
|---|---|---|
| [`?search=`](search/README.md) | Simple "full-text" search: one term `LIKE`-matched across several declared fields | [search/README.md](search/README.md) |
| [`?filter=`](filter.md) | **Precise** field interrogation: rich comparators, AND/OR/NOT | [filter.md](filter.md) |
| [`?facets=`](facets.md) | Declared **facets**: compact multi-select, existence/aggregate over relations | [facets.md](facets.md) |

## The mental model

> **`?search`** = "contains this word somewhere" (broad, fuzzy).
> **`?filter`** = "this field equals / is ≥ / matches …" (precise, boolean logic).
> **`?facets`** = "tick these declared boxes" (UI multi-select + edge/join relations that filters can't express).

## How they combine

All three feed **the same `FILTER`** of a `list()` query, on the current document `doc`, joined by `&&`:

```aql
FOR doc IN articles
    FILTER (LIKE(doc.name,@search_0,true) || …)   // ?search  → an OR group
        && doc.price >= @f0                        // ?filter
        && (doc.withStatus =~ @c0)                 // ?facets
    SORT  …                                         // ?sort        (see models.md)
    LIMIT …                                         // pagination   (see models.md)
    RETURN { … }                                    // projection   (Field::*, see edges-joins-projection.md)
```

So you can send all three in the same request — they stack (logical AND between them). `?search` forms its own internal `OR` group, then is AND-ed to the rest.

> **Beyond filtering.** A list query also has **sorting** ([`?sort`](../models.md#aqlsortable-notations)), **pagination** (`?limit`/`?offset`) and output **projection** (skins, `Field::*`). These do not *filter* and are out of scope here — see [models.md](../models.md) and [Edges and joins projection](../edges-joins-projection.md).

## Comparison table

| Criterion | `?search=` | `?filter=` | `?facets=` |
|---|---|---|---|
| **Purpose** | multi-field "contains" | precise field interrogation | UI facets + relations |
| **Target** | several declared fields (`searchable`) | one scalar field `doc.x` (+ paths `a.b`, expansion `field[*].sub`, `match`) | FIELD, IN (array), EDGE/JOIN, complex, AGGREGATE |
| **Syntax** | `?search=marc,marco` (CSV of terms) | explicit `{ "key","op","val","alt" }` | compact per key `{ "<facet>": <value> }` |
| **Operator** | always `LIKE %term%` (case-insensitive) | any `FilterComparator`/`FilterArrayComparator` | the same (reused) |
| **Internal combination** | OR over (each term × each field) | AND/OR/NOT, nestable (`["and"/"or"/"not", …]`) | none — each facet is independent, all AND-ed |
| **Model declaration** | `searchable` list | `AQL::FILTERS` → a `FilterType` per field | `Arango::FACETS` → a `Facet::TYPE` per facet |
| **Whitelist** | fixed `searchable` fields | typed `FILTERS` fields | **undeclared key = ignored** |
| **Relations (edge/join)** | no | **yes**, via a [relation path](filter.md#filtering-through-relations-edges--joins--nested-documents) (existence "has at least one linked match") | **first-class** (existence, aggregates, UI facets) |
| **Specific to** | — | dates (`now`/`tz`), `between`, `AT LEAST (n)`, `pluck`/`coalesce` alters | `between` (FIELD), `*_AGGREGATE`, `LIST*` aliases, `Facet::PROPERTY` |

## The shared foundation

Beyond the surface differences, all three rest on **the same building blocks** — which is what makes the API consistent:

- **Same AQL target.** Each produces a fragment of `buildListQuery`'s `FILTER`, evaluated on `doc`, combined with `&&`.
- **Same `op` vocabulary** (for filters & facets): [`FilterComparator`](filter.md#operators) (`eq/ne/gt/ge/lt/le/like/nlike/match/nmatch`) and `FilterArrayComparator` (`any.in/all.in/none.in…`). No bespoke codes; an unknown `op` falls back to the type default.
- **Same `alt` engine** (filters & facets): the `alterExpression()` / `resolveAltSides()` helpers wrap the compared field (`key`) and/or the value (`val`, `val:true` = mirror) with AQL functions (`lower`, `trim`, `abs`, `dateDay`…). The *output* counterpart is [`Field::ALTERS`](../edges-joins-projection.md#transforming-the-projected-value--fieldalters).
- **Same binds.** Only **values** are bound (`@bind`); user input never reaches the AQL text directly.
- **Same security contract.** Only `@bind`s are user-controlled; the `op` is whitelisted (`getAlias` → default); URL-provided keys/sub-fields are validated (`assertAttributeName`) or whitelisted by declaration.
- **Same leniency.** A malformed fragment (invalid value, dangerous sub-field, undeclared facet…) is **skipped and logged** (`warning`) — it never breaks the whole query.

## When to use which

- **`?search`** — a single **search bar** "type a word" sweeping a few text fields (`name`, `firstName`, `email`…). Simple, broad, no per-request configuration.
- **`?filter`** — an **advanced search**: boolean logic (AND/OR/NOT), date ranges with a timezone, fine comparators on a precise field — **including through a relation** (`location.name`, `employee[*].salary`: "has at least one linked match").
- **`?facets`** — **UI facets** (multi-value checkboxes) and **aggregates** over relations ("average of linked ≥ …", counts); for a plain boolean condition on a linked document, prefer `?filter`.

### The same intent, expressed several ways

```
# "contains "marc" in a text field"                   → ?search
?search=marc
# → (LIKE(doc.name,@s,true) || LIKE(doc.firstName,@s,true) || …)

# "the name equals exactly "marc""                    → ?filter
?filter={"key":"name","op":"eq","val":"marc"}
# → doc.name == @v

# "keyword cuisine OR jardin" (multi-select)          → ?facets
?facets={"keywords":"cuisine,jardin"}
# → TO_ARRAY([@k0,@k1]) ANY IN doc.keywords

# "linked to org 1234" (UI facet, multi-value)        → ?facets
?facets={"location":1234}
# → LENGTH(FOR v IN INBOUND doc orgs_places FILTER v._key==@v RETURN 1) > 0

# "whose linked org is named "Acme"" (relation)       → ?filter (relation path)
?filter={"key":"location.name","val":"Acme"}
# → LENGTH(FOR v IN INBOUND doc orgs_places FILTER v.name==@v LIMIT 1 RETURN 1) > 0
```

> `?filter=` **can also traverse relations** via a path (`location.name`, `employee[*].salary`) — see [Filtering through relations](filter.md#filtering-through-relations-edges--joins--nested-documents). Reserve `?facets=` for **UI facets** (multi-value checkboxes on a relation) and **aggregates**; use `?filter=` for a fine boolean condition on the linked document.

## Distance sorting (`?near=`)

Unlike the three levers above, which **restrict**, `?near=` **orders**: it ranks the list from nearest to farthest from a geographic point. It does not filter — pair it with a [`geo` filter](filter.md#distance-operator-geolocation) to bound a radius.

```
?near={"key":"geo","latitude":48.8566,"longitude":2.3522}
# → SORT DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) ASC
```

`?near=` provides the **anchor point** (a Schema.org `GeoCoordinates` attribute, short aliases `lat`/`lng`/`lon` accepted) and exposes a **synthetic `distance` sort key** driven by `?sort=` — which stays the **single ordering authority**:

| Request | Sort |
|---|---|
| `?near=…` alone | `distance` ASC (default, nearest first) |
| `?near=…&sort=-distance` | farthest first |
| `?near=…&sort=distance,name` | distance then name (you pick the priority) |
| `?near=…&sort=name` | name only — distance **not** appended (explicit `?sort` decides) |
| `?sort=distance` without `?near=` | dropped (no anchor) |

Typical combination — the 10 **nearest** museums within 5 km:

```
?near={"key":"geo","latitude":48.8566,"longitude":2.3522}
&filter=[{"key":"type","val":"museum"},{"key":"geo","op":"distance","val":{"latitude":48.8566,"longitude":2.3522},"max":5000}]
&limit=10
```

`DISTANCE` operates on two scalars → **index-accelerated** sort with a two-field [`GeoIndex`](../clients/indexes.md). Coordinates are bound only when a `distance` criterion is actually emitted (never an unused bind). See the [geospatial functions](../aql/aql-functions-geo.md).

## See also

- [Search `?search=`](search/README.md) — the multi-field `LIKE` search.
- [View search (ArangoSearch)](search/overview.md) — the relevance-ranked `?search=` (model-declared View).
- [Filters `?filter=`](filter.md) — comparators, `alt`, AND/OR/NOT, dates, `between`, `AT LEAST`, `distance`.
- [Facets `?facets=`](facets.md) — the type catalogue (FIELD/IN/EDGE/JOIN/complex/aggregate).
- [Internal filtering `AQL::CONDITIONS`](filter-internal.md) — server-only conditions (never exposed to the URL).
- [ArangoSearch (views)](../clients/arangosearch.md) — advanced full-text (analyzers, scoring); to be distinguished from the simple `?search`.
- [`Documents` models](../models.md) — sorting, pagination and the list-query lifecycle.
