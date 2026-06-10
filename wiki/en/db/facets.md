# HTTP facets `?facets=`

Alongside [`?filter=` filtering](filter.md), the framework exposes a **facets**
system on `GET` routes backed by a [`Documents`](../models.md) model. Where a
filter compares a **scalar field of the current document**, a facet answers
**relational or multi-valued** questions: "documents linked to this vertex",
"those whose array contains these values", "those having a joined document that
matches several fields"…

The client sends its intent as JSON in the `?facets=` URL parameter; the
framework turns it into AQL `FILTER` fragments with bind variables and runs it
against the target collection.

This page documents:

1. [Facets vs filters](#facets-vs-filters) — which one to use.
2. The [URL syntax](#url-syntax) `?facets=`.
3. The [model-side declaration](#model-side-declaration) (`Arango::FACETS` + `Facet::TYPE`).
4. The [facet type catalogue](#facet-type-catalogue), with concrete examples and generated AQL.
5. The [`op` operators](#op-operators), [negation](#negation) and [default behaviours](#default-behaviours).
6. [Security](#security-and-aql-injection) (injection guard).
7. [Facet counts `?facetCounts=`](#facet-counts-facetcounts) (breakdowns alongside the list).

## Facets vs filters vs search

Facets are one of a model's three filtering levers, alongside [`?filter=`](filter.md) and [`?search=`](search.md). The **full comparison table** (target, syntax, declaration, strengths, shared foundation, "when to use which") lives in the bridge page [**Search & filtering**](search-and-filtering.md).

In short: `?facets=` shines for **compact multi-select** and for the **relation existentials/aggregates** (edge/join) that filters can't express; it **reuses the same `op` vocabulary and the same `alt` engine** as filters. All three combine in one request (each produces a slice of the `FILTER`, joined with `&&`).

## URL syntax

The `?facets=` parameter is a **JSON object** whose every key is the **name of a
facet declared** on the model, and the value the filtering intent:

```
?facets={"withStatus":"draft","keywords":"cuisine,jardin"}
```

- The JSON must be URL-encoded (most HTTP clients do it).
- A key **absent from the model declaration is silently ignored** (security: no
  non-whitelisted facet is executable).
- A facet whose construction fails (invalid value, unsafe sub-field…) is
  **dropped and logged** (`warning`) — it never breaks the whole query.

In PHP:

```php
$facets = [ 'withStatus' => 'draft' , 'keywords' => 'cuisine,jardin' ] ;
$url    = '/articles?facets=' . urlencode( json_encode( $facets ) ) ;
```

## Model-side declaration

Every exposable facet is declared under the **`Arango::FACETS`** key (= `'facets'`)
at model construction. Each entry carries at least a **`Facet::TYPE`**:

```php
use oihana\arango\enums\Arango ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Facet ;
use oihana\arango\models\enums\filters\FilterComparator ;

$articles = new Documents
([
    Arango::FACETS =>
    [
        'withStatus' => [ Facet::TYPE => Facet::FIELD ] ,
        'keywords'   => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'keywords' ] ,
        'location'   => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' ] ,
        'author'     => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::FIELDS => 'name' ] ,
    ]
]) ;
```

> The URL key (`"withStatus"`) and the targeted **document property** may differ:
> see [`Facet::PROPERTY`](#property-aliasing).

Common configuration keys:

| Key | Role | Default |
|---|---|---|
| `Facet::TYPE` | The facet type (required). | — |
| `Facet::PROPERTY` | The targeted document property (alias of the URL key). | the facet key |
| `Facet::OP` | The comparison operator (type-dependent). | `eq` (except `IN` → `any.in`, `FIELD` → `match`) |
| `AQL::FIELDS` | The searched field(s) (EDGE/JOIN), CSV or list. | `_key` |
| `AQL::EDGE` | The edge collection (EDGE / EDGE_COMPLEX). | — |
| `AQL::COLLECTION` | The joined collection (JOIN / JOIN_COMPLEX). | — |
| `AQL::KEY` | The field on the joined collection. | `_key` |
| `AQL::ARRAY` | Join on an **array** of keys (`IN`). | `false` |

## Facet type catalogue

The examples below use concrete collections (those of the `FacetIntegrationTest` harness).

### `Facet::FIELD` — scalar field comparison

Filters on a simple document property (status, id, price…). CSV values are `OR`-ed,
a leading `-` negates. **Default operator: `match` (`=~`, regex)** — for exact
equality, set `op: eq`.

```php
'withStatus' => [ Facet::TYPE => Facet::FIELD ] ,
'price'      => [ Facet::TYPE => Facet::FIELD , Facet::OP => FilterComparator::GE ] ,
```
```
?facets={"withStatus":"draft"}                    // (doc.withStatus =~ @0)            ⚠ regex: "draft" also matches "predraft"
?facets={"withStatus":"draft,review"}             // (doc.withStatus =~ @0 || doc.withStatus =~ @1)
?facets={"withStatus":"-draft"}                    // (doc.withStatus !~ @0)
?facets={"withStatus":{"op":"eq","val":"draft"}}   // (doc.withStatus == @0)            exact
?facets={"price":{"op":"ge","val":100}}            // (doc.price >= @0)                 numeric (type preserved)
?facets={"name":{"op":"like","val":"jo%"}}         // (doc.name LIKE @0)
```
Operators: `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `like`, `nlike`, `match` (default), `nmatch`.

### `Facet::IN` — array membership *(aliases `LIST`, `LIST_FIELD`, `LIST_FIELD_SORTED`)*

Filters on an **array** document property. **Default operator: `any.in`** (the
document has **at least one** of the values). Accepts a CSV, a list, or an
`{op, val}` object.

```php
'keywords' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'keywords' ] ,
```
```
?facets={"keywords":"cuisine,jardin"}                        // TO_ARRAY([@0,@1]) ANY IN doc.keywords   (cuisine OR jardin)
?facets={"keywords":["cuisine","jardin"]}                    // array form, same result
?facets={"keywords":{"op":"all.in","val":"cuisine,jardin"}}  // ALL IN  : has BOTH
?facets={"keywords":{"op":"none.in","val":["cuisine"]}}      // NONE IN : has NEITHER
```
Operators (from `FilterArrayComparator`): `any.in` (default), `all.in`, `none.in`, `any.nin`, …

> `LIST`, `LIST_FIELD` and `LIST_FIELD_SORTED` are **historical aliases** of `IN`
> (operator `any.in`). `LIST_FIELD_SORTED` appends a `SORT POSITION(...)` ordering
> by the requested values' order.

### `Facet::EDGE` — existence of a linked vertex *(simple)*

"Keep documents linked (or not linked) to a vertex through an **INBOUND** edge
traversal". Matches one or more vertex fields (`AQL::FIELDS`, OR), configurable operator.

```php
'location' => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' ] ,
```
```
?facets={"location":1234}            // LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location._key == @0 RETURN doc_location._key) > 0
?facets={"location":"1234,5678"}     // … == @0 || … == @1 …                            (linked to 1234 OR 5678)
?facets={"location":"-1234"}         // LENGTH(…) == 0                                  (NOT linked to 1234)
?facets={"location":"1234,-5678"}    // (LENGTH(…>0) && LENGTH(…==0))                   (linked to 1234 AND not to 5678)
```
**Multi-field search (the former `THESAURUS`)** — search a term across several
vertex fields with `like`:
```php
'subjects' => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'has_subject' ,
                AQL::FIELDS => '_key,name,alternateName' , Facet::OP => 'like' ] ,
```
```
?facets={"subjects":"art"}  // LENGTH(FOR doc_subjects IN INBOUND doc has_subject
                            //   FILTER (doc_subjects._key LIKE @0 || doc_subjects.name LIKE @0 || doc_subjects.alternateName LIKE @0)
                            //   RETURN doc_subjects._key) > 0
```

### `Facet::EDGE_COMPLEX` — linked vertex matching several fields *(complex)*

Like `EDGE`, but the value is an **object** `{field: condition}` and **all**
fields must match **the same vertex** (AND). Each field accepts a value, a list
(OR) and `-` negation (inline `!=`).

```php
'numbers' => [ Facet::TYPE => Facet::EDGE_COMPLEX , AQL::EDGE => 'livestocks_has_numbers' ] ,
```
```
?facets={"numbers":{"value":"459"}}                  // LENGTH(FOR doc_numbers IN INBOUND doc livestocks_has_numbers FILTER doc_numbers.value == @… RETURN doc_numbers._key) > 0
?facets={"numbers":{"value":"459","kind":"ear"}}     // … value == @ && kind == @ …      (same vertex)
?facets={"numbers":{"value":["459","460"]}}          // … (value == @0 || value == @1) …
?facets={"numbers":{"value":"-459","kind":"ear"}}    // … value != @ && kind == @ …      (negation inline on the same vertex)
```

### `Facet::JOIN` — existence of a key-joined document *(simple)*

The **key-join** counterpart of `EDGE` (no edge: a join by attribute). "Keep
documents having at least one joined document whose field matches the value".
The join is `doc_join.<KEY> == doc.<PROPERTY>`.

```php
'author' => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' ,
              Facet::PROPERTY => 'authorId' , AQL::KEY => '_key' , AQL::FIELDS => 'name' ] ,
```
```
?facets={"author":"alice"}        // LENGTH(FOR doc_author IN authors FILTER doc_author._key == doc.authorId && doc_author.name == @0 RETURN 1) > 0
?facets={"author":"alice,bob"}    // … && (doc_author.name == @0 || doc_author.name == @1) …
?facets={"author":"-spammer"}     // … == 0                                              (excludes posts linked to "spammer")
?facets={"author":{"op":"like","val":"al"}}  // … doc_author.name LIKE @0 …
```
- `AQL::KEY`: the field on the joined collection (default `_key`). `Facet::PROPERTY`:
  the field on the main document (default the facet key).
- `AQL::ARRAY => true`: the join becomes `doc_join.<KEY> IN doc.<PROPERTY>` (the main
  document holds an **array** of keys).

### `Facet::JOIN_COMPLEX` — joined document matching several fields *(complex)*

The key-join counterpart of `EDGE_COMPLEX`. **Object** value `{field: condition}`,
fields **AND-ed** on the same joined document.

```php
'comments' => [ Facet::TYPE => Facet::JOIN_COMPLEX , AQL::COLLECTION => 'comments' ,
                AQL::KEY => 'postId' , Facet::PROPERTY => '_key' ] ,
```
```
?facets={"comments":{"status":"approved"}}              // LENGTH(FOR doc_comments IN comments FILTER doc_comments.postId == doc._key && doc_comments.status == @… RETURN 1) > 0
?facets={"comments":{"status":"approved","score":"5"}}  // … status == @ && score == @ …
?facets={"comments":{"status":["a","b"]}}               // … (status == @0 || status == @1) …
?facets={"comments":{"status":"-spam"}}                 // … status != @ …                 (negation inline)
```
Topologies covered by `AQL::KEY` / `Facet::PROPERTY` / `AQL::ARRAY`: one-to-one
(the document holds the key), reverse one-to-many (joined docs reference the
document), one-to-many by array.

### `Facet::EDGE_AGGREGATE` / `Facet::JOIN_AGGREGATE` — aggregate over linked documents

Instead of testing for the mere **existence** of a linked document (`EDGE`/`JOIN` ⇒ `LENGTH(…) > 0`), these facets **aggregate a numeric field** over **all** linked documents and compare the result to a threshold:

```
AGG(FOR doc_x IN <source> [FILTER <join>] RETURN doc_x.<field>) <op> @threshold
```

- `EDGE_AGGREGATE` reaches linked vertices through an **`INBOUND doc <edge>`** traversal (no FILTER);
- `JOIN_AGGREGATE` iterates a collection with **`FILTER doc_x.<KEY> == doc.<PROPERTY>`** (or `IN` when `AQL::ARRAY`).

The request value is the object **`{agg, field, op, val}`**; every key is optional on the URL and falls back to the definition:

| Key | Role | Default |
|---|---|---|
| `agg` | the aggregator: `avg`, `sum`, `min`, `max`, `count` | `Facet::AGG`, else `count` |
| `field` | the aggregated numeric field of the linked document (ignored by `count`) | `AQL::FIELDS` |
| `op` | the threshold comparator (`ge`, `gt`, `le`, `lt`, `eq`, `ne`) | `Facet::OP`, else `ge` |
| `val` | the threshold (numeric) — **required** (otherwise the facet is skipped) | — |

A **scalar** value is read as the threshold directly (`?facets={"comments":5}` ⇒ defaults `count`/`ge`).

#### Example 1 — `JOIN_AGGREGATE` (key-join)

**Articles** and their **comments**; a comment points to its article through `articleId`. We want *"articles whose average comment score is ≥ 4"*.

```php
'comments' => [
    Facet::TYPE     => Facet::JOIN_AGGREGATE ,
    AQL::COLLECTION => 'comments' ,  // joined collection
    AQL::KEY        => 'articleId' , // joined side  (default _key)
    Facet::PROPERTY => '_key' ,      // main side    (default the facet key)
    Facet::AGG      => 'avg' ,       // default aggregator
    AQL::FIELDS     => 'score' ,     // default aggregated field
    Facet::OP       => 'ge' ,        // default comparator
] ,
```
```
?facets={"comments":{"agg":"avg","field":"score","op":"ge","val":4}}
// (LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) > 0
//  && AVERAGE(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN doc_comments.score) >= @comments_0)

?facets={"comments":{"val":4}}                       // same (config defaults: avg / score / ge)
?facets={"comments":{"agg":"count","val":3}}         // at least 3 comments
?facets={"comments":{"agg":"sum","field":"score","val":10}}    // sum of scores >= 10
?facets={"comments":{"agg":"min","field":"score","val":3}}     // worst score >= 3
?facets={"comments":{"agg":"count","op":"lt","val":2}}         // lightly commented (fewer than 2, but at least 1)
```
`AQL::ARRAY => true` switches the join to `doc_x.<KEY> IN doc.<PROPERTY>` (the main document holds an array of keys).

#### Example 2 — `EDGE_AGGREGATE` (edge graph)

**Organisations** and their yearly **balance sheets** linked by a `balance_edges` edge (the sheet points to the org). We want *"organisations whose average linked balance-sheet revenue is ≥ 1,000,000"*.

```php
'balanceSheets' => [
    Facet::TYPE => Facet::EDGE_AGGREGATE ,
    AQL::EDGE   => 'balance_edges' , // edge collection (INBOUND doc)
    Facet::AGG  => 'avg' ,
    AQL::FIELDS => 'revenue' ,
    Facet::OP   => 'ge' ,
] ,
```
```
?facets={"balanceSheets":{"agg":"avg","field":"revenue","op":"ge","val":1000000}}
// (LENGTH(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN 1) > 0
//  && AVERAGE(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN doc_balanceSheets.revenue) >= @balanceSheets_0)

?facets={"balanceSheets":{"agg":"sum","field":"revenue","val":5000000}}  // cumulative revenue >= 5M
?facets={"balanceSheets":{"agg":"count","op":"ge","val":3}}              // at least 3 balance sheets
?facets={"balanceSheets":{"agg":"max","field":"revenue","val":2000000}} // best year >= 2M
```

> **`count` generalizes the existential.** `{"agg":"count","op":"gt","val":0}` reproduces exactly the `LENGTH(…) > 0` of the `EDGE`/`JOIN` facets.

> ⚠️ **Empty linked sets.** An aggregate facet only ever matches documents that have **at least one** linked document (hence the `LENGTH(…) > 0 && …` guard in the AQL). This is deliberate: in AQL `AVERAGE([])`/`MIN([])`/`MAX([])` yield `null` (and `SUM([])`/`COUNT([])` yield `0`), and since `null` sorts **below** every number, a `lt`/`le` threshold would otherwise spuriously match documents with **no** linked document at all.
>
> Example: with three orgs `o1` (sheets 1.2M / 0.9M), `o2` (sheet 0.2M) and `o3` (no sheet), the query `?facets={"balanceSheets":{"agg":"min","field":"revenue","op":"lt","val":500000}}` returns **only `o2`** — `o3` is excluded by the guard, whereas without it `MIN([]) = null < 500000` would have surfaced it.

> **No `-` negation and no `alt`** on aggregate facets: the `op` already carries the direction (`ne`/`lt`/…), and both the field and the threshold are numeric. A URL-provided `field` is validated (`assertAttributeName`) and an unknown `agg` makes the facet a no-op (skipped + logged).

### `Facet::ARRAY_COMPLEX` — embedded array of objects *(complex)*

"Keep documents whose **embedded array** property holds at least one element
matching the conditions". **Object** value `{sub-field: condition}`.

```php
'workshops' => [ Facet::TYPE => Facet::ARRAY_COMPLEX ] ,
```
```
?facets={"workshops":{"breeding.alternateName":"pig"}}            // LENGTH(FOR doc_workshops IN doc.workshops FILTER doc_workshops.breeding.alternateName == @… RETURN 1) > 0
?facets={"workshops":{"breeding.alternateName":["pig","cattle"]}} // … == @0 || == @1 …    (an element pig OR cattle)
?facets={"workshops":{"breeding.alternateName":["-pig","cattle"]}}// … != @0 && != @1 …    (an element neither pig nor cattle)
```

## `op` operators

Facets **reuse the filter vocabulary** — no bespoke codes:

- Scalar ([`FilterComparator`](filter.md#operators)): `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `like`, `nlike`, `match`, `nmatch`.
- Array ([`FilterArrayComparator`](filter.md)): `any.in`, `all.in`, `none.in`, `any.nin`, `all.nin`, `none.nin`, …

`op` is set either in config (`Facet::OP`) or per request inside an
`{ "op": "…", "val": … }` object. An unknown `op` falls back to the type default
(never an injection — see below).

The `FIELD` facet also accepts the **`between`** operator (inclusive range), with
`min`/`max` keys instead of `val`; an omitted bound drops its side (one-sided):

```
?facets={"price":{"op":"between","min":100,"max":200}}
// (doc.price >= @price_min && doc.price <= @price_max)
```

## `alt` transformations

Like [filters](filter.md#alt-transformations), a facet can wrap the comparison with AQL functions (`lower`, `trim`, `abs`, `dateDay`…). `alt` acts on the **compared field** (left) and/or the **value** (right):

- `alt:"lower"` / `alt:["trim","lower"]` → **field only** (`LOWER(doc.x) == @v`).
- `alt:{ "key":<chain>, "val":<chain> }` → one chain per side.
- `alt:{ "key":<chain>, "val":true }` → `val:true` = **mirror** (same chain on both sides), for a symmetric comparison (e.g. case-insensitive equality).

### Two places, the URL wins

`alt` is declared **either in the model definition** (`Facet::ALT`, a default for every request), **or in the URL request** (`{op,val,alt}`, per request). When both are present, **the URL wins** — exactly like `op`.

**① Frozen in the definition** — the email is case-insensitive for everyone; the client sends a raw value:
```php
Arango::FACETS => [
    Prop::EMAIL => [
        Facet::TYPE => Facet::FIELD ,
        Facet::OP   => FilterComparator::EQ ,
        Facet::ALT  => [ 'key' => 'lower' , 'val' => true ] , // default applied to every request
    ] ,
]
```
```
?facets={"email":"JEAN@X.COM"}
// (LOWER(doc.email) == LOWER(@0))
```

**② Provided by the URL** — no `alt` in the definition, the client decides:
```
?facets={"email":{"op":"eq","val":"JEAN@X.COM","alt":{"key":"lower","val":true}}}
// (LOWER(doc.email) == LOWER(@0))
```

**③ The URL overrides the definition** — definition `upper`, request `lower` ⇒ it is `lower`:
```
?facets={"email":{"val":"jean@x.com","alt":{"key":"lower","val":true}}}
// (LOWER(doc.email) == LOWER(@0))
```

### On linked facets (EDGE / JOIN)

`alt` wraps the **linked-document field** and the value, inside the `LENGTH(…)`:
```php
Prop::LOCATION => [
    Facet::TYPE => Facet::EDGE , Facet::EDGE => 'orgs_places' ,
    AQL::FIELDS => 'name' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ,
]
```
```
?facets={"location":"paris"}
// LENGTH(FOR v IN INBOUND doc orgs_places FILTER LOWER(v.name) == LOWER(@0) RETURN …) > 0
```

> ⚠️ **Extractors vs normalizers** — same rule as filters: for an **extractor** (`dateYear`, `count`…) the supplied value is *already* the target, keep the string form `alt:"dateYear"` (field only); for a **symmetric normalizer** (`lower`, `abs`…), use the object form or `val:true`.

### On complex facets (`EDGE_COMPLEX` / `JOIN_COMPLEX` / `ARRAY_COMPLEX`)

For complex facets, `alt` is declared **in the definition only** (`Facet::ALT`) and applies **globally to every sub-field** of the `{sub-field : condition}` object:

```php
Prop::NUMBERS => [
    Facet::TYPE => Facet::EDGE_COMPLEX , Facet::EDGE => 'has_numbers' ,
    Facet::ALT  => [ 'key' => 'lower' , 'val' => true ] , // applies to EVERY sub-field
]
```
```
?facets={"numbers":{"value":"459","kind":"EAR"}}
// LENGTH(FOR v IN … FILTER LOWER(v.value) == LOWER(@0) && LOWER(v.kind) == LOWER(@1) RETURN …) > 0
```
The structural join key (`doc_x.<KEY> == doc.<PROPERTY>` of a `JOIN_COMPLEX`) is **never** wrapped — only the sub-field conditions are.

> **Limitation (deliberate, Option A).** On complex facets `alt` is **global**: you cannot (yet) target a single sub-field, nor provide it per request from the URL. This covers the main use case ("this linked facet is case-insensitive"). **Per-sub-field** granularity (a `{sub-field:{val,alt}}` form in the URL) is technically possible but **not planned at this stage** — it can be added later if a concrete need arises.

### On the `Facet::IN` facet (array membership)

`Facet::IN` (and its `LIST` / `LIST_FIELD` / `LIST_FIELD_SORTED` aliases) accepts `alt` from the definition **and** the URL, like FIELD/EDGE/JOIN. One specificity: the compared property is an **array**, so the field side is **projected element-wise** (`doc.tags[* RETURN LOWER(CURRENT)]`) — a plain `LOWER(doc.tags)` would return `null`. The value side wraps each requested value, and any `SORT POSITION(...)` stays consistent:

```
?facets={"tags":{"val":["TECH","News"],"alt":{"key":"lower","val":true}}}
// TO_ARRAY([LOWER(@0),LOWER(@1)]) ANY IN doc.tags[* RETURN LOWER(CURRENT)]
```

Covered: **`FIELD`, `EDGE`, `JOIN`, `IN`** (+ `LIST*` aliases) — field + value, from the definition **or** the URL — and **`EDGE_COMPLEX` / `JOIN_COMPLEX` / `ARRAY_COMPLEX`** (global, from the definition). No injection risk: function names are whitelisted (an unknown function is a no-op), only values are bound.

## Negation

The `-` prefix semantics **depend on the type**, deliberately:

| Type | `-value` means | AQL |
|---|---|---|
| `FIELD` | flips the operator to its negative (`match`→`nmatch`, `eq`→`ne`, `like`→`nlike`); AND-ed group | `doc.x !~ @` |
| `IN` | use `op: none.in` at the set level | `… NONE IN doc.x` |
| `EDGE` / `JOIN` *(simple)* | **exclusion**: the document is linked to none of the negated values | `LENGTH(…) == 0` |
| `EDGE_COMPLEX` / `JOIN_COMPLEX` / `ARRAY_COMPLEX` | **inline negation**: there exists a linked doc whose field **≠** value | `… != @ …` (inside `LENGTH(…) > 0`) |

> For complex facets, negation is **inline existential** ("there exists an
> element ≠ X"), not "exclude documents containing X" — the only semantics
> consistent with matching several fields on the same linked document.

## Default behaviours

| Type | default `op` | default field(s) | value shape |
|---|---|---|---|
| `FIELD` | `match` (`=~`) | the key (or `Facet::PROPERTY`) | scalar / CSV / `{op,val,alt}` |
| `IN` (+ aliases) | `any.in` | the key (or `Facet::PROPERTY`) | CSV / list / `{op,val,alt}` |
| `EDGE` | `eq` | `_key` (`AQL::FIELDS`) | scalar / CSV / `{op,val,alt}` |
| `JOIN` | `eq` | `_key` (`AQL::FIELDS`) | scalar / CSV / `{op,val,alt}` |
| `EDGE_COMPLEX` | `eq`/`!=` per field | the object keys | object `{field:cond}` *(+ global `Facet::ALT`)* |
| `JOIN_COMPLEX` | `eq`/`!=` per field | the object keys | object `{field:cond}` *(+ global `Facet::ALT`)* |
| `ARRAY_COMPLEX` | `eq`/`!=` per field | the object keys | object `{field:cond}` *(+ global `Facet::ALT`)* |

Several facets in one request are joined with `&&`.

## Security and AQL injection

The contract is strict: **only bound values (`@bind`) are under user control**.

- **Values** always go behind a parameterized bind (never injectable).
- **Operators** are whitelisted (`getAlias` → default when unknown).
- **Facet keys** are whitelisted (the model's `Arango::FACETS`; absent key → ignored).
- The **sub-field names** of the complex facets (coming from the URL and
  interpolated into `doc.<field>`) are validated by
  [`assertAttributeName`](helpers.md#injection-guard--isattributename--assertattributename):
  an unsafe name makes the facet fail (dropped + `warning`), and no fragment ever
  reaches the AQL.

## Facet counts `?facetCounts=`

The facets above **filter** the list. To show, alongside the list, the **number
of documents per value** of each facet (the sidebar "Category: Cooking (42),
Travel (17)"), request **counts**:

```
GET /articles?facetCounts=category,keywords
```

- Dimensions are keys of the already-declared `Arango::FACETS` (the filterable facets become the counted facets); an unknown key is ignored.
- v1 supports `Facet::FIELD` (scalar field) and `Facet::IN` (array membership, unwound); other types are skipped.
- Counts are **conjunctive**: computed over the **already-filtered** set (same `?filter` / `?facets` / `?search` as the list). With an active [View search](search-views.md), every counting sub-query iterates the View with the **same `SEARCH`** as the list, so the buckets reflect exactly the displayed set.

Buckets are returned under the `facets` key of the standard success envelope,
next to `total`, **without changing** the document list:

```json
{
  "status": "success",
  "url": "https://api.example.org/articles?facetCounts=category,keywords",
  "count": 50,
  "total": 120,
  "facets": {
    "category": [ {"value":"Cooking","count":42}, {"value":"Travel","count":17} ],
    "keywords": [ {"value":"bio","count":31}, {"value":"local","count":12} ]
  },
  "result": [ /* …filtered documents… */ ]
}
```

Generated AQL (one `LET` sub-query per dimension, see [`aqlCollect`](../aql/aql-operations.md#aqlcollect)):
```aql
LET category = (FOR doc IN @@articles FILTER <same filters> COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN { value, count })
LET keywords = (FOR doc IN @@articles FILTER <same filters> FOR item IN doc.keywords COLLECT value = item WITH COUNT INTO count SORT count DESC RETURN { value, count })
RETURN { category, keywords }
```

> This is the right tool for **several independent breakdowns** in one response.
> To turn the list itself into **one** aggregation, see [Grouping `?groupBy=` / `?group=`](grouping.md).

## See also

- [HTTP filters `?filter=`](filter.md) — comparators, `alt` transforms, compound conditions.
- [HTTP grouping `?groupBy=` / `?group=`](grouping.md) — turn the list into an aggregation.
- [AQL helpers `db/helpers/`](helpers.md) — `isAttributeName` / `assertAttributeName`, AQL introspection.
- [Bind variables `db/binds/`](binds.md) — safe placeholders.
- [`Documents` and `Edges` models](../models.md) — `Arango::FACETS` declaration.
