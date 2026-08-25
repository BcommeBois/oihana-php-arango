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

Facets are one of a model's three filtering levers, alongside [`?filter=`](filter.md) and [`?search=`](search/README.md). The **full comparison table** (target, syntax, declaration, strengths, shared foundation, "when to use which") lives in the bridge page [**Search & filtering**](search-and-filtering.md).

In short: `?facets=` shines for **compact multi-select** and for the **relation existentials/aggregates** (edge/join) that filters can't express; it **reuses the same `op` vocabulary and the same `alt` engine** as filters. All three combine in one request (each produces a slice of the `FILTER`, joined with `&&`).

> The `alt` engine being the same, so are its refusals: a `Facet::ALT` naming an unknown function is **refused**, not ignored. See [A name the catalog does not carry is refused](filter.md#a-name-the-catalog-does-not-carry-is-refused).

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
> The same declaration can be **counted**: `?facetCounts=` buckets the linked
> vertices, see [Counting linked documents](#counting-linked-documents-facetedge--facetjoin).

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

> The same declaration can be **counted**: `?facetCounts=` buckets the joined
> documents, see [Counting linked documents](#counting-linked-documents-facetedge--facetjoin).

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
| `field` | the aggregated numeric field of the linked document (ignored by `count`) — **must belong to the `AQL::FIELDS` whitelist** (see *Permission on the aggregated field*) | `AQL::FIELDS` (first entry) |
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

> **No `-` negation and no `alt`** on aggregate facets: the `op` already carries the direction (`ne`/`lt`/…), and both the field and the threshold are numeric. A URL-provided `field` is validated (`assertAttributeName`) **and must belong to the `AQL::FIELDS` whitelist** (see below); an unknown `agg` makes the facet a no-op (skipped + logged).

#### Permission on the aggregated field (`AQL::FIELDS` whitelist + `AQL::MODEL`)

The aggregated field can come from the **URL** (`{"field":"…"}`). Left free, it would be an **oracle**: `{"agg":"max","field":"salary",…}` plus a dichotomy on `val` reconstructs a **bound** of an otherwise hidden field. Two layers close it.

**Layer 1 — whitelist, fail-closed.** The URL may only pick a field **declared** in `AQL::FIELDS`: a **string** is the single allowed field, a **list** is the allowed set (its first entry being the default). A field outside the set — or any field when **nothing** is declared — **neutralises the facet** (`false`).

The setup. A facet that declares a single field, and a request asking for another:

```php
'balanceSheets' => [ Facet::TYPE => Facet::EDGE_AGGREGATE , AQL::EDGE => 'balance_edges' , AQL::FIELDS => 'revenue' ] ,
```
```
?facets={"balanceSheets":{"agg":"max","field":"revenue","val":X}}   // ✅ in the list → aggregates revenue
?facets={"balanceSheets":{"agg":"max","field":"salary","val":X}}    // ❌ outside → facet neutralised (false)
```
To allow several fields: `AQL::FIELDS => [ 'revenue' , 'ebitda' ]`.

**Layer 2 — read permission of the target field (opt-in `AQL::MODEL`).** When the facet declares its **target model**, the aggregated field inherits that model's `Field::REQUIRES`, **per request**:

```php
'balanceSheets' => [
    Facet::TYPE => Facet::EDGE_AGGREGATE , AQL::EDGE => 'balance_edges' ,
    AQL::FIELDS => [ 'revenue' , 'ebitda' ] , AQL::MODEL => Models::BALANCE ,
] ,
```

If `revenue` carries `Field::REQUIRES => 'finance:read'` in the `BALANCE` model, the facet is **neutralised** for a user without that grant (and aggregates normally for an authorized one). Without `AQL::MODEL`, this layer is **skipped** — only the whitelist applies.

> ⚠️ **Migration.** An aggregate facet that let the URL choose the `field` **without** declaring `AQL::FIELDS` must now declare it (the same *fail-closed* switch as `?sort=` / `?groupBy=`). A facet already declaring `AQL::FIELDS` and querying that same field is unchanged.

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

> ⚠ When a request's `{op,val,alt}` object **overrides** the `alt` declared on the facet, the overriding chain's parameters are **bound** — see [A parameter supplied by a request is a value](filter.md#-a-parameter-supplied-by-a-request-is-a-value). The declared `alt` keeps the interpolation.

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

## Permission (`REQUIRES`)

Like filters, a facet on a field **hidden from reading** (`Field::REQUIRES`) leaks: used as a filter it lets that field narrow the set; via `?facetCounts=` it returns its **distinct values and their counts in clear** (a direct oracle).

The permission resolves by **inheritance** from the homonymous field in `$fields`, **or** from a `Field::REQUIRES` declared on the facet definition itself (it is already an array):

```php
public array $fields = [ 'name' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
public array $facets = [ 'salary' => [ Facet::TYPE => Facet::FIELD ] ] ; // inherits from $fields
// or explicit: 'salary' => [ Facet::TYPE => Facet::FIELD , Field::REQUIRES => 'hr:read' ]
```

| Surface | A refusal → |
|---|---|
| `?facets=` (facet-filter) | **neutralised to `false`** (like a filter — never loosened) |
| `?facetCounts=` (distribution) | **dimension dropped** (removes an output, loosens nothing) |

> **Fail-open** as for filters (no `REQUIRES` or no authorizer → normal facet). **Aggregated field**: an aggregate facet's field is locked by a **whitelist** (`AQL::FIELDS`) and, optionally, by the **target model's** `Field::REQUIRES` (`AQL::MODEL`) — see *Permission on the aggregated field*. **Relation facets** (EDGE/JOIN): locked explicitly with a `Field::REQUIRES` **on the facet** (no auto-inheritance from a homonymous relation). See [Field projection](../projection.md) and [Sorting](sort.md#sort-permission).

## Facet counts `?facetCounts=`

The facets above **filter** the list. To show, alongside the list, the **number
of documents per value** of each facet (the sidebar "Category: Cooking (42),
Travel (17)"), request **counts**. Counts **never restrict** the list — they
tally over whatever the list already shows, so they never conflict with
`?filter=` / `?facets=` (they *inherit* those filters):

```
GET /articles?facetCounts=category,keywords
```

- Dimensions are keys of the already-declared `Arango::FACETS` (the filterable facets become the counted facets); an unknown key is ignored.
- Supported types: `Facet::FIELD` (scalar field), `Facet::IN` (array membership, unwound), **object-array sub-fields via `[*]`** (e.g. `offers[*].priceCurrency`) and the **linked** `Facet::EDGE` / `Facet::JOIN` facets (see below); other types are skipped.
- Counts are **conjunctive**: computed over the **already-filtered** set (same `?filter` / `?facets` / `?search` as the list). With an active [View search](search/overview.md), every counting sub-query iterates the View with the **same `SEARCH`** as the list, so the buckets reflect exactly the displayed set.

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

### Counting an object-array sub-field (`[*]`)

The count side reaches the **same paths** the [filter](filter.md) and
[search](search/overview.md) sides already accept. A `Facet::PROPERTY` carrying the
`[*]` array-expansion marker (e.g. `offers[*].priceCurrency`) counts a **sub-field
of an embedded array of objects**: the array is unwound and the sub-field
projected, so **each element is counted as its own bucket**. This is pure
notation parity — it adds no new restricting power and does **not** couple facet
counts to `?filter=`.

Take a product with an embedded `offers` array:

```json
{ "_key": "prod-1", "category": "tools",
  "offers": [ { "priceCurrency": "EUR" }, { "priceCurrency": "USD" } ] }
```

Declare the counted facet, pointing at the array sub-field:

```php
Arango::FACETS => [
    'currency' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'offers[*].priceCurrency' ] ,
]
```
```
GET /products?filter={"category":"tools"}&facetCounts=currency
```

The count sub-query unwinds the array and projects the sub-field (and still
inherits the list filter):

```aql
LET currency = (FOR doc IN @@products FILTER doc.category == @0
                FOR item IN doc.offers
                COLLECT value = item.priceCurrency WITH COUNT INTO count
                SORT count DESC RETURN { value, count })
```
```json
"facets": { "currency": [ {"value":"EUR","count":120}, {"value":"USD","count":45} ] }
```

- The `[*]` marker is the signal: it **overrides** the declared `FIELD` / `IN` type.
- **Each `[*]` is one `FOR` hop**, so nested object arrays are counted per leaf element; the path between two markers descends within the element (`a[*].b.c[*].d`).
- The container and sub-field are guarded by [`assertAttributeName`](helpers.md#injection-guard--isattributename--assertattributename): a dangerous path fails the facet, never reaching the AQL.
- `offers[*]` with no sub-field counts the element itself (like a plain `IN` facet).

Nested arrays unwind one hop per marker — e.g. `offers[*].prices[*].currency`:

```aql
LET currency = (FOR doc IN @@products FILTER <same filters>
                FOR item  IN doc.offers
                FOR item2 IN item.prices
                COLLECT value = item2.currency WITH COUNT INTO count
                SORT count DESC RETURN { value, count })
```

> This is the right tool for **several independent breakdowns** in one response.
> To turn the list itself into **one** aggregation, see [Grouping `?groupBy=` / `?group=`](grouping.md).

### Counting linked documents (`Facet::EDGE` / `Facet::JOIN`)

The situation. An article's **location** is not a field of the article: it is a
vertex reached through an edge, or a document joined by key. The facet already
**filters** on it (`?facets={"location":"1234"}`) — the sidebar now wants the
number **next to each value**: "Paris (12), Lyon (8)". A linked dimension counts
the documents at the **other end of the relation**, over the same filtered set as
any other count.

Declare which field of the **related** document becomes the bucket value with
`Facet::VALUE` (default `_key`) — everything else is the declaration the facet
already filters on:

```php
Arango::FACETS => [
    // Edge: the vertices reached by an INBOUND traversal
    'location' => [
        Facet::TYPE  => Facet::EDGE ,
        AQL::EDGE    => 'organizations_places' ,
        Facet::VALUE => 'name' ,  // the vertex field feeding `value` (default _key)
    ] ,
    // Key-join: the documents joined on doc.authorId == author._key
    'author' => [
        Facet::TYPE     => Facet::JOIN ,
        AQL::COLLECTION => 'authors' ,
        Facet::PROPERTY => 'authorId' , // main side, unchanged (the join, not the bucket)
        Facet::VALUE    => 'name' ,
    ] ,
]
```
```
GET /articles?facetCounts=location,author
```

The relation is the source of the count: an edge traversal needs no predicate —
it already targets the right vertices — while a join opens the collection and
narrows it with its match:

```aql
LET location = (FOR doc IN @@articles FILTER <same filters>
                FOR doc_location IN INBOUND doc organizations_places
                COLLECT value = doc_location.name WITH COUNT INTO count
                SORT count DESC RETURN { value, count })
LET author   = (FOR doc IN @@articles FILTER <same filters>
                FOR doc_author IN authors FILTER doc_author._key == doc.authorId
                COLLECT value = doc_author.name WITH COUNT INTO count
                SORT count DESC RETURN { value, count })
```
```json
"facets": { "location": [ {"value":"Paris","count":12}, {"value":"Lyon","count":8} ] }
```

- **`Facet::VALUE` names the bucket, never the join.** On a `JOIN`,
  `Facet::PROPERTY` keeps its meaning — the main side of the join predicate — so
  the two never compete. Bucketing on a **label** (`name`) rather than on the
  default `_key` makes the bucket self-sufficient: the UI has nothing left to
  resolve.
- **The join declaration is shared with the filtering facet**, so a dimension
  counts over exactly the relation it filters on: `AQL::KEY` (joined side,
  default `_key`) covers the reverse one-to-many, and `AQL::ARRAY => true` turns
  the predicate into a membership test when the main side holds an **array of
  keys** (`doc_tags._key IN doc.tagIds`).
- **A linked facet never unwinds the main document.** Its source is the relation,
  so a `[*]` marker in its `Facet::PROPERTY` is a mis-declaration and is
  **refused**, rather than silently counting the raw keys instead of the joined
  documents. The declared names — edge collection, joined collection, join keys
  and `Facet::VALUE` — are guarded by
  [`assertAttributeName`](helpers.md#injection-guard--isattributename--assertattributename);
  a **missing** collection is refused too, rather than compiling to a truncated
  `FOR … IN`.
- **Counts rows by default**, so see `Facet::DISTINCT` below — over-counting is
  more common here than on an array (two parallel edges to the same vertex, or
  several joined documents).
- The **aggregate** facets (`EDGE_AGGREGATE` / `JOIN_AGGREGATE`) compare a
  threshold rather than naming a dimension: there is no bucket to count, so they
  stay skipped, as do the `*_COMPLEX` facets.
- ⚠ **Permission**: the projection inheritance cannot speak for a field of
  **another** collection, so a linked dimension is gated by the `Field::REQUIRES`
  declared **on the facet itself** — exactly as on the filtering side. See
  [Permission](#permission-requires).
- **Works under a [View search](search/overview.md)**: when `?search=` is active the
  counting sub-query iterates the View and the traversal starts from the View row
  (`doc._key` is available, so `Facet::DISTINCT` works there too) — measured live.
  ⚠ On a **cluster**, a traversal inside a sub-query needs a `WITH` naming the
  vertex collections, which the query does not emit; the library targets a single
  server (the same already applies to an `EDGE` facet used as a filter).

### Counting distinct documents per bucket (`Facet::DISTINCT`)

The setup. A facet that **unwinds** — whether a `[*]` sub-field
(`offers[*].sellerId`), an `Facet::IN` membership facet (`keywords`) or a
**linked** `EDGE` / `JOIN` facet — counts the **rows** by default, not the
documents. If the **same** seller appears in 3 offers of the **same** product,
that product is counted **3 times** in the bucket.

That is consistent when you want "how many elements match". But a UI sidebar
usually expects "how many **documents** match" — the same number the equivalent
existence filter `?filter={"key":"offers[*].sellerId","val":"X"}` returns, which
counts **documents** (`LENGTH(...) > 0`). The per-element count then shows an
**inflated** number that does not match the filter's result count.

Take a product whose same `sellerId` repeats across several offers:

```json
{ "_key": "prod-1",
  "offers": [ { "sellerId": "acme" }, { "sellerId": "acme" }, { "sellerId": "globex" } ] }
```

- **Per-element** count (default): `acme` → 2, `globex` → 1.
- **Per-document** count: `acme` → 1, `globex` → 1 (the product appears once per
  bucket, as with `?filter=`).

To switch to per-document counting, set the **opt-in** option
`Facet::DISTINCT => true` on the facet declaration:

```php
Arango::FACETS => [
    'seller' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'offers[*].sellerId' , Facet::DISTINCT => true ] ,
]
```

Only the **aggregation** changes: the `WITH COUNT INTO count` becomes an
`AGGREGATE count = COUNT_DISTINCT( doc._key )`. The unwind, the sort and the
`{ value, count }` projection stay identical:

```aql
LET seller = (FOR doc IN @@products FILTER <same filters>
              FOR item IN doc.offers
              COLLECT value = item.sellerId AGGREGATE count = COUNT_DISTINCT( doc._key )
              SORT count DESC RETURN { value, count })
```

- **Opt-in, backward compatible**: without the flag the behaviour (per-element
  count) is **unchanged**.
- Applies to **every unwinding facet**: the `[*]` sub-fields (single- and
  multi-hop), the `Facet::IN` / `Facet::LIST` / `Facet::LIST_FIELD` /
  `Facet::LIST_FIELD_SORTED` family, **and** the linked `Facet::EDGE` /
  `Facet::JOIN` facets — where two parallel edges to the same vertex, or several
  joined documents, over-count exactly like a repeated array element.
- The distinct always targets the **root document** key (`doc._key`), whatever
  the `[*]` hop depth (`a[*].b[*].c` still counts distinct root documents).
- **No effect** on a scalar `Facet::FIELD` facet: it already emits one row per
  document, so the flag is ignored (the `WITH COUNT` is kept).
- Touches neither the `?metaOnly=` mode (nor its deprecated `?facetsOnly=` alias)
  nor the exact `total` — they already come from a dedicated `count()`.

### Keeping only the biggest buckets (`Facet::LIMIT`)

The situation. A dimension returns **every** value it finds. A sidebar shows ten
entries — and a linked vocabulary can hold thousands of them, all serialized,
all transferred, for a list nobody unrolls. `Facet::LIMIT => n` keeps the **n
biggest** buckets:

```php
Arango::FACETS => [
    'category' => [ Facet::TYPE => Facet::FIELD , Facet::LIMIT => 10 ] ,
]
```

Only the tail changes — the `LIMIT` closes it **after** the sort, so what
survives is the biggest buckets, not an arbitrary n of them:

```aql
LET category = (FOR doc IN @@articles FILTER <same filters>
                COLLECT value = doc.category WITH COUNT INTO count
                SORT count DESC LIMIT 10 RETURN { value, count })
```

- **Applies to every type** — the scalar field, the `Facet::IN` family, the `[*]`
  sub-fields and the linked `EDGE` / `JOIN` facets — because they all end on the
  same tail. It combines freely with `Facet::DISTINCT`: the count becomes
  per-document, and the limit still keeps the biggest of those buckets.
- **Opt-in, backward compatible**: without it, every bucket is returned as before.
- ⚠ **A non-positive or non-integer limit is refused**, not ignored. `LIMIT 0`
  would emit **no `LIMIT` clause at all** and silently return *everything* — the
  opposite of what the declaration asks. Omit the option for all the buckets.

#### Overriding it per request (`?facetCountsLimit=`)

The declaration is a **default**, not a ceiling: one request can raise it, lower
it, or cancel it. The sidebar takes the declared ten; the "all filters" panel
asks for everything.

```
GET /articles?facetCounts=keywords                      // the declaration decides
GET /articles?facetCounts=keywords&facetCountsLimit=25  // the 25 biggest buckets
GET /articles?facetCounts=keywords&facetCountsLimit=all // every bucket
```

- **Three distinct states**, and the middle one matters: *absent* hands the
  decision to the declaration, an *integer* overrides it, and `all` cancels it.
  Absent is therefore **not** the same as `all` — one obeys the model, the other
  overrules it.
- **One value for the whole query**: it applies to every dimension of the same
  `?facetCounts=`. A per-dimension limit would need a different syntax and is not
  supported.
- **No ceiling to enforce**, and that follows from how counts are built: the
  `COLLECT` computes every bucket whatever the limit, which only trims what
  travels. A request asking for more buckets than exist receives the ones that
  exist — exactly what it would get with no limit at all.
- ⚠ **`all` is a word, never `0`.** Same rule everywhere: a limit is a positive
  integer, and "every bucket" is said with the keyword. `?facetCountsLimit=0`
  (or `-5`, or `2.5`, or anything unreadable) is refused with a **`400`** naming
  what to write — where a faulty *declaration* earns a `500`, since the caller
  cannot fix it.
- ⚠ **Ties are cut arbitrarily.** Buckets are ordered by count alone, so when the
  n-th and the (n+1)-th share a count, which one survives is not deterministic.
  A stable top-N would need a tie-breaker the sort does not carry today.
- The **total** is unaffected: it comes from a dedicated `count()`, not from the
  sum of the buckets — which a limited dimension can no longer provide anyway.

### Counts without the documents (`?metaOnly=`)

A faceted-search sidebar often needs **only the counts** — the documents are
fetched by a separate, paginated call. Add `?metaOnly=true` to **skip the
document-fetch query entirely**: the `result` array comes back empty while the
`facets` buckets, and an **exact `total`**, are still computed.

```
GET /products?facetCounts=category&metaOnly=true
```

```json
{
  "status": "success",
  "count": 0,
  "total": 120,
  "facets": {
    "category": [ {"value":"tools","count":80}, {"value":"garden","count":40} ]
  },
  "result": []
}
```

- **Why not `?limit=0`?** `limit=0` means **no limit** (return everything) — it is
  *not* "zero results". `?metaOnly=` is the dedicated, unambiguous signal.
- The `total` is **exact** in every case (scalar *and* array facets): it comes from
  a dedicated `count()` query that inherits the **same** `?filter=` / `?facets=` /
  `?search=` as the counts, never from summing the (possibly multi-valued) buckets.
- Accepts any boolean form: `true`, `1`, `yes`, `on`.
- Used **alone** (no `?facetCounts=`), it still returns the exact `total` with an
  empty `result` and no `facets` — a cheap "how many match?" probe.
- **Permissions are enforced.** This mode's counts and `total` go through the
  **same** `Field::REQUIRES` / `Facet::REQUIRES` gates as the normal path: a
  dimension hidden from a user stays hidden here. `?metaOnly=` is **not** a back
  door to the counts of a forbidden field.

#### Making it the default of a dedicated endpoint

The situation. You expose a route that *is*, by nature, a facet probe — a sidebar,
a histogram, a bounds slider. There, returning the documents on every call makes no
sense: you want `metaOnly` to be **true by default**, without forcing every client
to append `?metaOnly=true`.

Rather than repeat it in the URL, set it once in the controller `$init` (its DI
definition), through the `Arango::META_ONLY` key:

```php
$init = [ Arango::META_ONLY => true , /* … */ ] ;
```

The controller stores that default at construction time (`initializeMetaOnly()`)
and `list()` reads it as the base value. The precedence, weakest to strongest:

1. `false` — the original default: **nothing changes** for existing controllers
   (backward compatible);
2. `Arango::META_ONLY => true` in `$init` — your durable default, scoped to that
   endpoint;
3. `?metaOnly=false` in the URL — the client always keeps the last word and gets
   the documents back.

In other words: the "facets" endpoint no longer returns the documents by default,
yet stays able to yield them on demand. Other controllers are untouched.

> **`?facetsOnly=` is deprecated — prefer `?metaOnly=`.** The older `?facetsOnly=`
> flag (counts only) is superseded by the generic `?metaOnly=`, which likewise
> skips the documents but **also** keeps the [bounds `?bounds=`](bounds.md), not
> only the counts. `?facetsOnly=` stays a truthy **alias** (the controller ORs the
> two flags) — no change for existing calls, but use `?metaOnly=` in any new code.

## See also

- [HTTP bounds `?bounds=`](bounds.md) — the `{ min, max }` extent of a numeric field, beside the list.
- [HTTP filters `?filter=`](filter.md) — comparators, `alt` transforms, compound conditions.
- [HTTP grouping `?groupBy=` / `?group=`](grouping.md) — turn the list into an aggregation.
- [AQL helpers `db/helpers/`](helpers.md) — `isAttributeName` / `assertAttributeName`, AQL introspection.
- [Bind variables `db/binds/`](binds.md) — safe placeholders.
- [`Documents` and `Edges` models](../models.md) — `Arango::FACETS` declaration.
