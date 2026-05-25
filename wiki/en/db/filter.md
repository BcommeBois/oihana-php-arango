# HTTP filters `?filter=`

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
| `alt` | no | Transformation applied to `doc.<key>` before comparison. |

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

Examples:

```
?filter={"key":"status","val":"closed","op":"ne"}
?filter={"key":"price","val":100,"op":"gt"}
?filter={"key":"name","val":"%john%","op":"like"}
?filter={"key":"role","val":["admin","owner"],"op":"in"}
```

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
| `position` | Position of a value | `search`, `returnIndex` |
| `reverse` | Reverse order | — |
| `sorted` | Sort | — |
| `sortedUnique` | Sort and deduplicate | — |
| `unique` | Deduplicate without sorting | — |
| `slice` | Extract a portion | `start`, `length` |

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
