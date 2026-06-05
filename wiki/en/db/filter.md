# HTTP filters `?filter=`

> `?filter=` is one of a model's three filtering levers. For the overview (differences and shared foundation with [`?search=`](search.md) and [`?facets=`](facets.md), "when to use which"), see [**Search & filtering**](search-and-filtering.md).

The framework exposes a declarative filtering system on `GET` routes backed by a [`Documents`](../models.md) model. The client sends its intent as JSON in the `?filter=` URL parameter, the framework converts it into a `FILTER` AQL clause with *bind variables*, and executes it on the target collection.

This page documents:

1. The **URL syntax** of the `?filter=` parameter.
2. The available **operators** and **filter types**.
3. The **`alt` transformations** applied to the value before comparison (full catalog by type).
4. The **complex conditions** (array, AND/OR, nesting).
5. The **DI declaration** (`AQL::FILTERS` on a model).
6. The **best practices** and pitfalls (indexes, performance).

For **server-only** filtering (never exposed to the URL — sensitive fields, internal conditions), see [Internal filtering `AQL::CONDITIONS` + `AQL::BINDS`](filter-internal.md).

## URL syntax

### Base format

Each condition is a JSON object with the following four keys:

| Key | Required | Description |
|---|---|---|
| `key` | yes | Name of the field to filter (must be present in the model's `AQL::FILTERS`). |
| `val` | yes | Comparison value (scalar or array depending on operator). |
| `op` | no | Operator (`eq` by default). |
| `alt` | no | Transformation applied to `doc.<key>` before comparison; the object form `{key,val}` also applies it to the value (see below). |

```
?filter={"key":"email","val":"john@example.com"}
```

A single condition is enough. To combine several conditions, see [Complex conditions](#complex-conditions).

### URL encoding

The JSON must be URL-encoded. Most HTTP clients do it automatically:

```bash
# cURL example
curl 'https://api.example.com/users?filter=%7B%22key%22%3A%22email%22%2C%22val%22%3A%22john%40example.com%22%7D'
```

In PHP:

```php
$filter = [ 'key' => 'email' , 'val' => 'john@example.com' ] ;
$url    = '/users?filter=' . urlencode( json_encode( $filter ) ) ;
```

### The pipeline

```
URL ?filter={"key":"email","val":"john"}
  → JSON decoding
  → validation against AQL::FILTERS (per-key whitelist)
  → FilterType resolution for the key
  → alt application if present
  → AQL predicate generation: FILTER LOWER(doc.email) == @email
  → bind addition: { email: 'john' }
  → execution
```

Any key absent from `AQL::FILTERS` is **silently ignored** (security — no injection possible on a non-whitelisted field).

## Operators

The `op` values are defined by the `FilterComparator` enum.

| `op` | Semantics | Equivalent AQL output |
|---|---|---|
| `eq` (default) | Equal to | `doc.x == @val` |
| `ne` | Not equal to | `doc.x != @val` |
| `gt` | Greater than | `doc.x > @val` |
| `ge` | Greater than or equal | `doc.x >= @val` |
| `lt` | Less than | `doc.x < @val` |
| `le` | Less than or equal | `doc.x <= @val` |
| `like` | *Wildcard* match (`%`, `_`) | `LIKE(doc.x, @val, false)` |
| `in` | In the supplied list | `doc.x IN @val` |
| `nin` | Not in the list | `doc.x NOT IN @val` |
| `between` | Inclusive range (`min`/`max` keys instead of `val`) | `(doc.x >= @min && doc.x <= @max)` |

Examples:

```
?filter={"key":"status","val":"closed","op":"ne"}
?filter={"key":"price","val":100,"op":"gt"}
?filter={"key":"name","val":"%john%","op":"like"}
?filter={"key":"role","val":["admin","owner"],"op":"in"}
```

### The `between` operator (range)

`between` compares a field to an **inclusive range** via the **`min`** and **`max`** keys (no `val`). Available for the **number**, **string** and **date** types:

```jsonc
{"key":"price","op":"between","min":10,"max":50}
// FILTER (doc.price >= @min && doc.price <= @max)
```

**Bound omission — the semantics depend on the type:**
- **number / string**: an omitted bound is **dropped** → one-sided comparison.
  ```jsonc
  {"key":"price","op":"between","max":50}   // FILTER doc.price <= @max
  {"key":"price","op":"between","min":10}   // FILTER doc.price >= @min
  ```
- **date**: an omitted bound resolves to **now** → the range stays two-sided.
  ```jsonc
  {"key":"created","op":"between","min":"2024-01-01"}
  // FILTER (doc.created >= @min && doc.created <= DATE_ISO8601(DATE_NOW()))
  ```

**Timezone (`tz`)**: for a date, the JSON `tz` applies to **both bounds**:
```jsonc
{"key":"created","op":"between","min":"2024-01-01","max":"2024-12-31","tz":"Europe/Paris"}
// FILTER (doc.created >= DATE_LOCALTOUTC(@min,@tz) && doc.created <= DATE_LOCALTOUTC(@max,@tz))
```

The compared field stays `alt`-aware (e.g. `"alt":"abs"` → `(ABS(doc.price) >= @min && …)`).

## Filter types

Each filterable field is typed via `FilterType::*` in the model's `AQL::FILTERS` declaration. The type **determines** how the value is validated and which subset of `alt` is compatible.

| Type | Server-side validation | Useful operators | Compatible `alt` examples |
|---|---|---|---|
| `FilterType::STRING` | Non-empty string | `eq`, `ne`, `like`, `in`, `nin` | `lower`, `upper`, `trim`, `substring`, `length`, `md5` |
| `FilterType::NUMBER` | Integer or float | `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `in`, `nin` | `abs`, `round`, `ceil`, `floor` |
| `FilterType::DATE` | ISO 8601 or Unix timestamp ms | `eq`, `ne`, `gt`, `ge`, `lt`, `le` | `dateYear`, `dateMonth`, `dateDayOfWeek`, `dateFormat` |
| `FilterType::BOOL` | `true` / `false` | `eq`, `ne` | None (booleans aren't transformable) |
| `FilterType::ARRAY` | JSON array | `eq`, `in`, `nin`; quantified operators | `count`, `length`, `first`, `last`, `sum`, `avg` |
| `FilterType::VIRTUAL` | No AQL clause emitted | — | Special case: see [filter-internal.md](filter-internal.md) |

```php
// DI-side declaration
AQL::FILTERS =>
[
    Prop::ACTIVE     => FilterType::BOOL   ,
    Prop::CLIENT_ID  => FilterType::STRING ,
    Prop::CREATED    => FilterType::DATE   ,
    Prop::IDENTIFIER => FilterType::STRING ,
    Prop::PRICE      => FilterType::NUMBER ,
    Prop::VALUES     => FilterType::ARRAY  ,
]
```

## `alt` transformations

The `alt` key applies an AQL function to `doc.<key>` **before** comparison. It is the HTTP equivalent of the [PHP-side AQL functions](../aql/aql-functions-strings.md) — but exposed as a short string.

### Supported syntaxes

```jsonc
// Simple function without parameter
{"key":"name","val":"john","alt":"lower"}
// FILTER LOWER(doc.name) == "john"

// Function with parameters
{"key":"code","val":"ABC","alt":["substring", 0, 3]}
// FILTER SUBSTRING(doc.code, 0, 3) == "ABC"

// Function chain (left to right, inner to outer)
{"key":"email","val":"john","alt":["trim","lower"]}
// FILTER LOWER(TRIM(doc.email)) == "john"

// Mixed chain (parameterized + simple functions)
{"key":"code","val":"ABC","alt":["trim",["substring",0,3],"upper"]}
// FILTER UPPER(SUBSTRING(TRIM(doc.code), 0, 3)) == "ABC"
```

Evaluation order is **inner-to-outer**: the first array element is applied first, the last is applied last.

### Applying `alt` to the value (symmetric comparison)

By default `alt` wraps only the **field** (left side): `LOWER(doc.email) == @v`. The value stays raw — which prevents, for instance, a case-insensitive equality. The **object form** applies the transformation to **both sides**:

```jsonc
// Object form: one chain per side
{"key":"email","val":"JEAN@X.COM","alt":{"key":"lower","val":"lower"}}
// FILTER LOWER(doc.email) == LOWER(@v)

// val:true → mirror: applies the field-side chain to the value side too
{"key":"email","val":"JEAN@X.COM","alt":{"key":"lower","val":true}}
// FILTER LOWER(doc.email) == LOWER(@v)

// the mirror also works on a function chain
{"key":"name","val":" John ","alt":{"key":["trim","lower"],"val":true}}
// FILTER LOWER(TRIM(doc.name)) == LOWER(TRIM(@v))

// each side is independent: here, only the value is transformed
{"key":"email","val":"JEAN@X.COM","alt":{"val":"lower"}}
// FILTER doc.email == LOWER(@v)
```

When the **value is an array** (e.g. `op:in`), the chain is applied to **each element** via an inline projection, without changing the *bind* (which still holds the whole array):

```jsonc
{"key":"category","op":"in","val":["TECH","NEWS"],"alt":{"key":"lower","val":true}}
// FILTER LOWER(doc.category) IN @v[* RETURN LOWER(CURRENT)]
```

> ⚠️ **Extractors vs normalizers.** For an **extractor** (`dateYear`, `count`, `length`…), the supplied value is *already* the target (`val:2024`): keep the string form `alt:"dateYear"` (field side only). For a **symmetric normalizer** (`lower`, `trim`, `abs`, `dateDay`…), use the object form or `val:true`. **You** decide via the form — there is no automatic classification.

100% backward compatible: the string and list forms (`"lower"`, `["trim","lower"]`) keep acting on the field only.

### On nested filters (array expansion `[*]` and `match`)

`alt` also applies **inside** array expansions. For a key `field[*].subField`, it wraps the inline condition `CURRENT.<subField>` (and its value):

```
?filter={"key":"contactPoint[*].email","val":"ADMIN@ACME.COM","alt":{"key":"lower","val":true}}
// LENGTH(doc.contactPoint[* FILTER LOWER(CURRENT.email) == LOWER(@v)]) > 0
```

For a `match` condition (several sub-fields on the same element), `alt` applies **globally to every sub-field** (same rule as complex facets):

```
?filter={"key":"additionalProperty[*]","match":{"propertyID":"X","value":"Y"},"alt":{"key":"lower","val":true}}
// LENGTH(doc.additionalProperty[* FILTER LOWER(CURRENT.propertyID) == LOWER(@0) && LOWER(CURRENT.value) == LOWER(@1)]) > 0
```

The leaves of **edge / join / document** traversals (`seller.name`, …) already inherit `alt` through the underlying flat filter. The **structural join key** (`j.id == doc.x`) stays **raw**.

### Catalog by category

> For the signature and detailed semantics of each function, see [String functions](../aql/aql-functions-strings.md), [Date functions](../aql/aql-functions-dates.md), [Numeric functions](../aql/aql-functions-numerics.md), [Array functions](../aql/aql-functions-arrays.md). This page lists their URL-side exposed versions.

#### Strings

| `alt` | Effect | Parameters |
|---|---|---|
| `lower` | Lowercase | — |
| `upper` | Uppercase | — |
| `trim` | Removes whitespace from both sides | `type` optional (0=both, 1=left, 2=right) |
| `ltrim` | Removes from the left | `chars` optional |
| `rtrim` | Removes from the right | `chars` optional |
| `substring` | Sub-string | `start`, `length` (optional) |
| `left` | N leftmost characters | `length` |
| `right` | N rightmost characters | `length` |
| `concat` | Concatenates | `...strings` |
| `concatSeparator` | Concatenates with separator | `separator`, `...strings` |
| `length` | String length | — |
| `charLength` | Character count (UTF-8) | — |
| `contains` | Contains substring | `search`, `caseInsensitive` |
| `startsWith` | Starts with | `prefix` |
| `findFirst` | First-occurrence position | `search`, `start`, `end` |
| `findLast` | Last-occurrence position | `search`, `start`, `end` |
| `split` | Split into array | `separator`, `limit` |
| `md5` / `sha1` / `sha256` / `sha512` | Hexadecimal *hash* | — |
| `crc32` / `fnv64` | Hexadecimal fingerprint | — |
| `toBase64` | Base64 encoding | — |
| `toHex` | Hex encoding | — |
| `encodeURIComponent` | URL encoding | — |
| `soundex` | English phonetic fingerprint | — |
| `levenshtein` | Levenshtein distance | `compare` |

#### Numeric

| `alt` | Effect | Parameters |
|---|---|---|
| `abs` | Absolute value | — |
| `ceil` | Upper rounding | — |
| `floor` | Lower rounding | — |
| `round` | Rounding | — |
| `sqrt` | Square root | — |
| `pow` | Power | `exponent` |
| `exp` / `exp2` | Exponential | — |
| `log` / `log10` / `log2` | Logarithms | — |
| `sin` / `cos` / `tan` | Trigonometry | — |
| `asin` / `acos` / `atan` | Inverse trigonometry | — |
| `atan2` | Two-argument arctangent | `x` |
| `degrees` | Converts radians → degrees | — |
| `radians` | Converts degrees → radians | — |

#### Arrays

| `alt` | Effect | Parameters |
|---|---|---|
| `count` / `length` | Element count | — |
| `countDistinct` | Distinct element count | — |
| `sum` | Sum | — |
| `average` | Average | — |
| `min` / `max` | Min / Max | — |
| `median` | Median | — |
| `percentile` | Percentile | `position`, `method` |
| `product` | Product | — |
| `first` / `last` | First / last element | — |
| `nth` | Element at position N | `position` |
| `pluck` | Project an array of objects onto a single sub-field | `field` |
| `position` | Position of a value | `search`, `returnIndex` |
| `reverse` | Reverse order | — |
| `sorted` | Sort | — |
| `sortedUnique` | Sort and deduplicate | — |
| `unique` | Deduplicate without sorting | — |
| `slice` | Extract a portion | `start`, `length` |

##### `pluck` — aggregate one field of an array of objects

Aggregates (`avg`, `sum`, `min`, `max`, `count`…) operate on an array of **scalars**. But a property is often an array of **objects** (order lines, readings…). `pluck` projects that array of objects onto **a single sub-field** before the aggregate. It relies on AQL's native inline projection `array[* RETURN CURRENT.<field>]` — the read-only sibling of the inline filter `[* FILTER …]`.

Chained with an aggregate, it lets you filter on, say, the **average basket** of an order whose lines are `{price, quantity}` objects:

```jsonc
// "orders whose average line price is ≥ 100"
{"key":"items","op":"ge","val":100,"alt":[["pluck","price"],"avg"]}
// FILTER AVERAGE(doc.items[* RETURN CURRENT.price]) >= @v
```

A few variations, to show the flexibility (`pluck` composes with any aggregate):

```jsonc
{"key":"items","op":"gt","val":1000,"alt":[["pluck","price"],"sum"]}    // total order amount > 1000
{"key":"items","op":"le","val":5,"alt":[["pluck","quantity"],"max"]}    // no line with more than 5 units
{"key":"readings","op":"ge","val":18,"alt":[["pluck","temp"],"median"]} // median reading temperature ≥ 18
```

The sub-field may be a **nested object path** (dotted notation), e.g. when each line carries an `offer` sub-object:

```jsonc
{"key":"items","op":"ge","val":100,"alt":[["pluck","offer.price"],"avg"]}
// FILTER AVERAGE(doc.items[* RETURN CURRENT.offer.price]) >= @v
```

> 🔒 The sub-field name (`price`, `offer.price`…) comes from the URL: it is validated by [`assertAttributeName`](helpers.md#injection-guard--isattributename--assertattributename) before interpolation — a dangerous name fails the filter, nothing reaches the AQL. *(A sub-field that is itself an array — `offers[*].price` — is not handled yet.)*

#### Dates

| `alt` | Effect | Parameters |
|---|---|---|
| `dateYear` / `dateMonth` / `dateDay` | Date component | — |
| `dateHour` / `dateMinute` / `dateSecond` / `dateMillisecond` | Time component | — |
| `dateDayOfWeek` | Day of the week (0-6, Sunday=0) | — |
| `dateDayOfYear` | Day of the year (1-366) | — |
| `dateDaysInMonth` | Days in the month | — |
| `dateIsoWeek` | ISO week (1-53) | — |
| `dateIsoWeekYear` | ISO week year | — |
| `dateQuarter` | Quarter (1-4) | — |
| `dateLeapYear` | Leap year | — |
| `dateAdd` | Add a duration | `amount`, `unit` |
| `dateSubtract` | Subtract a duration | `amount`, `unit` |
| `dateDiff` | Difference between two dates | `date2`, `unit` |
| `dateTrunc` | Truncate to unit | `unit` |
| `dateFormat` | Format the date | `format`, `useUTC` |
| `dateISO8601` | ISO 8601 format | — |
| `dateTimeStamp` | Unix timestamp (ms) | — |
| `dateTimezone` | Change timezone | `timezone` |
| `dateLocalToUTC` / `dateUTCToLocal` | Timezone conversion | `timezone` |
| `yesterday` / `tomorrow` | Relative date | — |

Units accepted by `dateAdd`, `dateSubtract`, `dateDiff`, `dateTrunc`: `year`, `month`, `week`, `day`, `hour`, `minute`, `second`, `millisecond` (match the `DateUnit` enum).

#### Conditional

| `alt` | Effect | Parameters |
|---|---|---|
| `coalesce` / `notNull` | First non-`null` value (= SQL `COALESCE`) | `...default values` |

`coalesce` (alias `notNull`) wraps the field in an AQL `NOT_NULL(...)` to **substitute a default value when the field is missing or `null`**, before the comparison:

```jsonc
// "discount == 0" treating a missing field as 0
{"key":"discount","op":"eq","val":0,"alt":[["coalesce",0]]}
// FILTER NOT_NULL(doc.discount, 0) == @v   →  documents with no `discount` match 0
```

You may pass **several** fallbacks (first non-`null` wins): `alt:[["coalesce", "doc.fallback", "N/A"]]`… but note:

> 🔒 The default values come from the URL: they are **always inlined as strict AQL literals** (via `json_encode` — quoted/escaped strings, never the raw passthrough). A default therefore **cannot** reference another field (`doc.other`) or a function: it is treated as literal data. This is deliberate (injection-safe).

## Complex conditions

### Condition array (AND by default)

```jsonc
?filter=[
    {"key":"active","val":true},
    {"key":"role","val":"admin"},
    {"key":"created","val":"2026-01-01","op":"ge"}
]
// FILTER doc.active == @active && doc.role == @role && doc.created >= @created
```

All conditions in the array are joined by `&&`. This is the most common form.

### OR logic — `logic` key

To combine with `||`, add a `logic` key in a root-level condition:

```jsonc
?filter={
    "logic":"or",
    "conditions":[
        {"key":"role","val":"admin"},
        {"key":"role","val":"owner"}
    ]
}
// FILTER (doc.role == @role_1 || doc.role == @role_2)
```

Accepted `logic` values are defined by the `FilterLogic` enum: `and` (default) and `or`.

### Nesting

The `{logic, conditions}` groups nest recursively:

```jsonc
?filter=[
    {"key":"active","val":true},
    {
        "logic":"or",
        "conditions":[
            {"key":"role","val":"admin"},
            {"key":"role","val":"owner"}
        ]
    }
]
// FILTER doc.active == @active && (doc.role == @role_1 || doc.role == @role_2)
```

## DI declaration (`AQL::FILTERS`)

Each `Documents` model declares the filterable keys in `AQL::FILTERS`. A key absent from this list is **silently ignored** URL-side — this guarantees a client cannot filter on a field the developer hasn't explicitly exposed.

```php
use oihana\arango\models\enums\filters\FilterType ;

AQL::FILTERS =>
[
    Prop::ACTIVE     => FilterType::BOOL   ,
    Prop::CLIENT_ID  => FilterType::STRING ,
    Prop::CREATED    => FilterType::DATE   ,
    Prop::IDENTIFIER => FilterType::STRING ,
    Prop::PRICE      => FilterType::NUMBER ,
    Prop::VALUES     => FilterType::ARRAY  ,
]
```

Convention: `AQL::FILTERS` is a subset of `AQL::FIELDS` — only fields actually exposed get filtered. An asymmetry (`FIELDS` without `FILTERS`) is legal: a returned but non-filterable field.

## Practical cases

### Case-insensitive search

```jsonc
{"key":"email","val":"john.doe@example.com","alt":["trim","lower"]}
// FILTER LOWER(TRIM(doc.email)) == @email
```

### Format validation

```jsonc
{"key":"postalCode","val":5,"alt":"length"}
// FILTER LENGTH(doc.postalCode) == 5

{"key":"sku","val":"PRD","alt":["substring",0,3]}
// FILTER SUBSTRING(doc.sku, 0, 3) == "PRD"
```

### Temporal filters

```jsonc
// Documents created in 2026
{"key":"created","val":2026,"alt":"dateYear"}

// Documents created on a Monday
{"key":"created","val":1,"alt":"dateDayOfWeek"}

// Documents created in the last 30 days
{"key":"created","val":"2026-04-17","op":"ge","alt":["dateSubtract",30,"day"]}
```

### Array filters

```jsonc
// Documents with at least 3 tags
{"key":"tags","val":3,"op":"ge","alt":"count"}

// Documents whose first tag is "featured"
{"key":"tags","val":"featured","alt":"first"}

// Documents whose score sum exceeds 100
{"key":"scores","val":100,"op":"gt","alt":"sum"}
```

### Array quantifiers — the `quant` key

On an array field, two **orthogonal** axes combine:

| Axis | Question | Where | Values |
|---|---|---|---|
| **Comparison** | how to compare one element? | `op` (+ `val`) or `match` | `eq`, `ge`, `in`… |
| **Quantifier** | how many elements must match? | `quant` | `any` *(default)*, `all`, `none`, `n` (≥ n) |

The comparator stays in `op`; `quant` says **how many elements** of the array must satisfy it. The same `quant` key covers **both families** of arrays:

- **scalar arrays** (numbers, strings) → AQL **array comparison** operator (`doc.scores ALL >= @v`);
- **object arrays** (`reviews[*].rating`, `contactPoint[*]` + `match`) → AQL **question-mark** operator (`doc.reviews[? ALL FILTER CURRENT.rating >= @v]`).

| `quant` | Meaning | Scalar (AQL) | Object (AQL) |
|---|---|---|---|
| `"any"` *(default)* | at least **1** | `… ANY <cmp> @v` | `…[? ANY FILTER …]` |
| `"all"` | **all** | `… ALL <cmp> @v` | `…[? ALL FILTER …]` |
| `"none"` | **none** | `… NONE <cmp> @v` | `…[? NONE FILTER …]` |
| `n` *(integer)* | **at least n** | `… AT LEAST (n) <cmp> @v` | `…[? AT LEAST (n) FILTER …]` |

> **Scalars vs objects — two AQL operators, one vocabulary.**
> On a **scalar** array, `quant` produces the **array comparison** operator (`doc.scores AT LEAST (2) >= @v`). On an **object** array, it produces the **question-mark** operator (`doc.reviews[? AT LEAST (3) FILTER CURRENT.rating >= @v]`), which requires a `FILTER`/`CURRENT`. You write the same `quant`; the framework picks the right form from the key.

#### Complete example — products with their customer ratings (scalar array)

```jsonc
// "products" collection
{ "_key":"A", "name":"Headset",  "ratings":[5,4,4,2] }
{ "_key":"B", "name":"Keyboard", "ratings":[5,3,2]   }
{ "_key":"C", "name":"Mouse",    "ratings":[4,4]     }
```

Need: "products that have **at least 3 ratings of 4 stars or more**".

```jsonc
?filter={"key":"ratings","op":"ge","val":4,"quant":3}
// FILTER doc.ratings AT LEAST (3) >= @value   (@value = 4)
```

| Product | ratings | ratings ≥ 4 | kept? |
|---|---|---|---|
| **A** | `[5,4,4,2]` | 5, 4, 4 → **3** | ✅ |
| B | `[5,3,2]` | 5 → 1 | ❌ |
| C | `[4,4]` | 4, 4 → 2 | ❌ |

→ Result: **only A**.

The same filter with the other quantifiers shows why the numeric quantifier is useful:

| `quant` | Meaning | AQL | Result |
|---|---|---|---|
| `"any"` | at least **1** rating ≥ 4 | `doc.ratings ANY >= 4` | A, B, C |
| `"all"` | **all** ratings ≥ 4 | `doc.ratings ALL >= 4` | C |
| `3` | **at least 3** ratings ≥ 4 | `doc.ratings AT LEAST (3) >= 4` | **A** |

`ANY` is too broad, `ALL` too strict: `n` (at least n) expresses exactly "enough qualifying elements".

```jsonc
// at least 3 values among those supplied
{"key":"scores","op":"in","val":[1,2,3],"quant":3}
// FILTER doc.scores AT LEAST (3) IN @value
```

#### Object arrays — `quant` + the question-mark operator

When each element is an **object**, the condition targets a sub-field (`reviews[*].rating`) or a multi-sub-field `match` (`contactPoint[*]`). `quant` then wraps the question-mark operator — which is what makes `ALL`/`NONE`/`AT LEAST` finally expressible (impossible with `LENGTH(...) > 0` alone):

```jsonc
// at least 3 reviews rated ≥ 4
{"key":"reviews[*].rating","op":"ge","val":4,"quant":3}
// doc.reviews[? AT LEAST (3) FILTER CURRENT.rating >= @v]

// all contacts verified
{"key":"contactPoint[*]","match":{"verified":true},"quant":"all"}
// doc.contactPoint[? ALL FILTER CURRENT.verified == @v]

// no variant out of stock
{"key":"variants[*]","match":{"stock":0},"quant":"none"}
// doc.variants[? NONE FILTER CURRENT.stock == @v]
```

> Without `quant`, an object array keeps its historical **existential** behaviour — `LENGTH(doc.reviews[* FILTER CURRENT.rating >= @v]) > 0` (at least one). Adding `quant` breaks nothing (backward compatible). `quant` applies only to **first-level** object arrays; on a nested array (`employee[*].contactPoint[*]`) it is **ignored** (the binding level would be ambiguous) and the existential behaviour is kept.

#### Compatibility & notations

- **Recommended form**: `quant` (uniform across scalar + object).
- **Legacy aliases** (still valid) on scalar arrays: `op:"all.ge"` / `"any.ge"` / `"none.ge"` and `op:["atLeast.ge", n]` (array form, element 0 the code, element 1 the threshold).
- `quant` **absent** = legacy behaviour unchanged (default `==` / existential `LENGTH(...) > 0`).
- `n` is cast to an **integer** (injection-safe); an unknown `quant` **rejects** the filter (`ValidationException`). The field stays `alt`-aware.

### Hash combinations

```jsonc
{"key":"password","val":"5f4dcc3b5aa765d61d8327deb882cf99","alt":"md5"}
// FILTER MD5(doc.password) == @password
```

## Best practices

### Performance and indexes

`alt` transformations **prevent index use** on the transformed field. The ArangoDB engine cannot pre-compute `LOWER(doc.email)` without applying the function to every document.

```jsonc
// WILL NOT use the 'email' index
{"key":"email","val":"john@example.com","alt":"lower"}

// Will use the 'email' index (if the value is already normalized in DB)
{"key":"email","val":"john@example.com"}
```

Golden rule: **normalize at insert time** rather than at filtering time as soon as load justifies it. Otherwise, accept the cost (reasonable for moderate volume) and use `alt` freely.

### Server-side validation

The framework validates:

- that the `key` is in `AQL::FILTERS`;
- that the `val` is compatible with the declared `FilterType` (string for `STRING`, number for `NUMBER`, ISO 8601 or ms for `DATE`, etc.);
- that the `op` operator is known;
- that the `alt` function (and its parameters) are valid.

Invalid conditions are **silently ignored** rather than rejected with 400. This choice protects the service from minor client errors but requires observability attention: a filter that "does nothing" deserves a check.

### Order of functions in `alt`

Order changes the result. Always **reduce data before transforming it**:

```jsonc
// Good order: substring before lower
{"alt":[["substring",0,3],"lower"]}
// LOWER(SUBSTRING(doc.x, 0, 3))

// Bad order: transforms the whole string then extracts
{"alt":["lower",["substring",0,3]]}
// SUBSTRING(LOWER(doc.x), 0, 3)
```

### Limit `alt` chain length

The longer the chain, the more expensive the generated AQL function. In practice, 2-3 transformations cover 95 % of needs.

## See also

- [Internal filtering `AQL::CONDITIONS` + `AQL::BINDS`](filter-internal.md) — server-only conditions, `FilterType::VIRTUAL`.
- [`Documents` and `Edges` models](../models.md) — `AQL::FILTERS` declaration in the model definition.
- [AQL functions](../aql/aql-functions-strings.md) — PHP equivalents of the transformations exposed here.
- [Glossary — Alteration](../getting-started/glossary.md#alteration-alt) — `alt` definition.
