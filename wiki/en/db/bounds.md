# HTTP bounds `?bounds=`

Alongside [`?facetCounts=` facet counts](facets.md#facet-counts-facetcounts), the framework exposes a **bounds** system on `GET` routes backed by a [`Documents`](../models.md) model.

A **bound** is the extent of a numeric field: the two values that frame it, its smallest (`min`) and its largest (`max`) over the displayed set. Where a facet count returns a **per-value distribution** ("Cooking (42), Travel (17)") — useful for a discrete dimension — a bound returns **two scalars** — exactly what you need to size a **range control** (min/max) on a continuous measure such as a width, a weight or a price.

The client names the fields to bound in the `?bounds=` URL parameter, the framework aggregates their `MIN` / `MAX` over the **same filtered set** as the list, and joins the result to the response, beside the documents.

This page documents:

1. [Bounds vs facet counts](#bounds-vs-facet-counts) — which to use.
2. The [URL syntax](#url-syntax) `?bounds=`.
3. The [model declaration](#model-declaration) (`AQL::BOUNDS`).
4. The [generated AQL](#generated-aql) (flat fields merged, nested measures).
5. The [`REQUIRES` permission](#permission-requires) (bound oracle).
6. [Bounds without the documents](#bounds-without-the-documents-metaonly) (`?metaOnly=`).

## Bounds vs facet counts

Both are computed beside the list, over the **same filtered set**, and **never restrict** it — they describe what the list already shows. They differ by their **output shape**:

| | Facet count (`?facetCounts=`) | Bound (`?bounds=`) |
|---|---|---|
| Output | a **distribution** `[ {value, count}, … ]` | **two scalars** `{ min, max }` |
| Target field | **discrete** (category, status, keyword) | **continuous** (width, weight, price) |
| UI use | a list of checkboxes | a min/max range control |

Asking for a distribution over a continuous price would return thousands of `{value, count}` rows — unusable. The bound answers the real question: "between which values should my slider span?". The two **combine** in a single call (discrete facets + numeric bounds, one round-trip).

## URL syntax

The `?bounds=` parameter is a **comma-separated list of field names**; each name must be a **declared bound** on the model:

```
GET /products?bounds=width,height,weight
```

- A key **absent from the declaration** is silently ignored (no non-whitelisted bound is computable).
- Bounds inherit the **same filters** as the list (`?filter=` / `?facets=` / `?search=`): adding a filter narrows the bounds to that subset.

Bounds are returned under the `bounds` key of the standard success envelope, beside `total`, **without changing** the document list:

```json
{
  "status": "success",
  "url": "https://api.example.org/products?bounds=width,height",
  "count": 50,
  "total": 120,
  "bounds": {
    "width":  { "min": 5,  "max": 240, "count": 8123 },
    "height": { "min": 10, "max": 300, "count": 7960 }
  },
  "result": [ /* …filtered documents… */ ]
}
```

Each bound also carries a **`count`**: the number of values that framed the extent (non-null, after exclusions) — so a client knows whether a range control is worth showing (`count` of 0 → no value, no slider).

`MIN` / `MAX` **ignore null values**: a field absent from a document does not skew its extent; a field with no non-null value in the set returns `{ "min": null, "max": null, "count": 0 }`.

## Model declaration

Each boundable field is declared under the **`AQL::BOUNDS`** key (= `'bounds'`) at model construction. The whitelist is **fail-closed**: a null `$bounds` makes **nothing** boundable, exactly like `$sortable` / `$groupable`.

Two input forms:

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Bound ;

$products = new Documents
([
    AQL::BOUNDS =>
    [
        'width'  => [ Bound::POSITIVE => true ] ,              // flat field, drop the <= 0
        'height' => [ Bound::POSITIVE => true ] ,
        'density' ,                                            // bare flat field (no exclusion)
        'weight' => [ Bound::PROPERTY => 'grossWeight' ] ,     // renamed property
        'price'  => [ Bound::PROPERTY => 'offers[*].price' ] , // measure nested in an object array
    ]
]) ;
```

- A **bare name** (`'density'`) bounds the homonymous top-level scalar field, with no exclusion.
- A **definition** (array) targets a differently-named or nested property (`Bound::PROPERTY`), and/or sets [exclusion options](#exclusion-options).

Configuration keys:

| Key | Role | Default |
|---|---|---|
| `Bound::PROPERTY` | The targeted document property (alias of the URL name, `[*]` path accepted). | the bound name |
| `Bound::POSITIVE` | `true` → keep only values `> 0`. | `false` |
| `Bound::MIN` | Lower edge of the **accepted domain**: excludes values `< MIN`. | *none* |
| `Bound::MAX` | Upper edge of the **accepted domain**: excludes values `> MAX`. | *none* |
| `Bound::IGNORE` | Sentinel value(s) to exclude — a scalar or a list. | *none* |
| `Bound::REQUIRES` | The permission subject(s) gating the bound. | *inherited from `AQL::FIELDS`* |

### Exclusion options

`MIN` / `MAX` ignore `null`, but **not** *sentinel* values: if `0` encodes "not filled" in your data, it drags the observed minimum to 0. The exclusion options map an excluded value to `null` (which `MIN` / `MAX` already ignore), **per field** — a document excluded from one bound still frames the others. They combine with a logical AND.

| Need | Declaration | Guard AQL |
|---|---|---|
| Ignore `<= 0` ("0 = not filled") | `[ Bound::POSITIVE => true ]` | `doc.x > 0 ? doc.x : null` |
| Accepted domain `[-50, 200]` (e.g. temperature) | `[ Bound::MIN => -50 , Bound::MAX => 200 ]` | `doc.x >= -50 && doc.x <= 200 ? doc.x : null` |
| Exclude sentinels | `[ Bound::IGNORE => [ 0, 5, 15 ] ]` | `doc.x NOT IN [0,5,15] ? doc.x : null` |

> ⚠️ **`Bound::MIN` / `MAX` (declaration) ≠ `min` / `max` (output).** In the declaration they bound the **accepted input domain** (a filter); in the output, `min` / `max` are the **observed** values. Same words, distinct roles.

## Generated AQL

**Flat fields share a single `COLLECT AGGREGATE`**: one pass over the filtered set frames every flat measure at once.

```aql
FOR doc IN @@products FILTER <same filters>
COLLECT AGGREGATE width_min = MIN(doc.width), width_max = MAX(doc.width), width_count = SUM(doc.width != null ? 1 : 0),
                  height_min = MIN(doc.height), height_max = MAX(doc.height), height_count = SUM(doc.height != null ? 1 : 0)
RETURN { width: { min: width_min, max: width_max, count: width_count }, height: { min: height_min, max: height_max, count: height_count } }
```

A **nested measure** `[*]` must unwind its array, so it cannot share the root `FOR` loop: it gets its own `LET` sub-query, merged into the flat block with `MERGE`:

```aql
LET __bounds = FIRST(( FOR doc IN @@products FILTER <same filters> COLLECT AGGREGATE … RETURN { … } ))
LET price    = FIRST(( FOR doc IN @@products FILTER <same filters> FOR item IN doc.offers COLLECT AGGREGATE lo = MIN(item.price), hi = MAX(item.price), cnt = SUM(item.price != null ? 1 : 0) RETURN { min: lo, max: hi, count: cnt } ))
RETURN MERGE( __bounds, { price: price } )
```

- The aggregates are **conjunctive**: computed over the **already-filtered** set (same `?filter` / `?facets` / `?search` as the list). With an active [View search](search/overview.md), the sub-query iterates the View with the **same `SEARCH`** as the list, so the bounds reflect exactly the displayed set.
- **Each `[*]` is a `FOR` loop**; nested arrays unwind one hop per marker (`offers[*].tiers[*].amount`).
- The container and the sub-field are guarded by [`assertAttributeName`](helpers.md#anti-injection-guard--isattributename--assertattributename): a dangerous path fails the bound, never reaching the AQL.

> This is the optimum reachable through `COLLECT`: bounding six flat fields costs **one** pass, not six. To turn the list itself into an aggregation, see [Grouping `?groupBy=`](grouping.md).

## Permission (`REQUIRES`)

A bound on a field **hidden from reading** (`Field::REQUIRES`) leaks **harder** than a count: a `{ min, max }` **is** a real value of the field (the lowest price, the dimension of a confidential product), not merely a tally. The gate is therefore **not optional**.

The permission resolves by **inheritance** from the homonymous field in `AQL::FIELDS`, **or** from a `Bound::REQUIRES` set directly on the bound definition:

```php
public array $fields = [ 'price' => [ Field::REQUIRES => 'sales:read' ] ] ; // projection
public array $bounds = [ 'price' ] ; // inherits from $fields
// or explicit: 'price' => [ Bound::PROPERTY => 'offers[*].price' , Bound::REQUIRES => 'sales:read' ]
```

A refused bound is **dropped** from the query (it removes an output, loosens nothing). Resolution works **at the exact sub-field** (via [`isPathAuthorized`](../projection.md)): a deeply locked `dimensions.width` is caught, not just its root.

> **Fail-open** identical to facets: no `REQUIRES` or no injected authorizer → normal bound. See [Field projection](../projection.md), [Facet permission](facets.md#permission-requires) and [Sort permission](sort.md#sort-permission).

## Bounds without the documents (`?metaOnly=`)

A search sidebar often needs **only the metadata** — bounds, counts, `total` — the documents being fetched by a separate, paginated call. Add `?metaOnly=true` to **skip the document-fetch query entirely**: the `result` array comes back empty, while the bounds, the facet counts and an **exact `total`** are still computed.

```
GET /products?facetCounts=category&bounds=width,height&metaOnly=true
```

```json
{
  "status": "success",
  "count": 0,
  "total": 120,
  "facets": { "category": [ {"value":"tools","count":80}, {"value":"garden","count":40} ] },
  "bounds": { "width": {"min":5,"max":240,"count":8123}, "height": {"min":10,"max":300,"count":7960} },
  "result": []
}
```

- `?metaOnly=` is the **generic** "the sidebar, not the documents" signal: it spans facets **and** bounds in a single documentless round-trip.
- It **supersedes** the older `?facetsOnly=` (counts only), kept as a truthy **alias** (deprecated) — the controller ORs the two flags, so existing calls are unchanged.
- Accepts any boolean form: `true`, `1`, `yes`, `on`.
- **The [`REQUIRES` permission](#permission-requires) applies identically.** This mode's bounds, counts and `total` go through the **same** gates as the normal path: a field hidden from reading yields its `{ min, max }` (or its counts) no more through `?metaOnly=` (or its deprecated `?facetsOnly=` alias) than through the list. The "metadata only" mode is not a back door.

## See also

- [HTTP facets `?facets=`](facets.md) — relational/multi-valued filtering and `?facetCounts=` counts.
- [HTTP filters `?filter=`](filter.md) — apply the chosen interval (`{"all":[{"key":"width","op":"ge","val":100},{"key":"width","op":"le","val":500}]}`).
- [HTTP grouping `?groupBy=`](grouping.md) — turn the list into an aggregation.
- [`Documents` and `Edges` models](../models.md) — `AQL::BOUNDS` declaration.
