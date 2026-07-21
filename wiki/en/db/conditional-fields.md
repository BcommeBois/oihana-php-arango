# Conditional fields — `Field::WHEN`

A scalar projection can be **guarded by a condition**: the field's value is computed
only when the condition holds, otherwise it falls back to an `else` branch. This is the
AQL counterpart of SQL `CASE WHEN … THEN … ELSE …`, rendered as a ternary:

```aql
price: doc.visibility == 'public' ? doc.price : null
```

- The **key is always present** — only the *value* switches. `Field::WHEN` never removes
  the key (that would require a `MERGE` and is intentionally out of scope; an absent value
  is expressed as `null`).
- The condition is **resolved at query time** from the document's own attributes (a per-row
  decision), unlike `Field::SKINS` / `Field::REQUIRES` which decide *inclusion* up front
  (per request / per permission). The three are orthogonal and compose.
- Condition values are **inlined** (not bound): a `WHEN` is developer-declared static
  configuration, never user input — see [Security](#security).

> Applies to the **default scalar projection only**. `Field::WHEN` on a typed/structural
> filter (`EDGES`, `JOINS`, `DOCUMENT`, `MAP`, `URL`, …) throws an `UnsupportedOperationException`.

## Quick start

```php
use oihana\arango\enums\Field ;

$fields =
[
    // show the real price to the public, the base price otherwise
    'price' =>
    [
        Field::WHEN => [ 'visibility' , 'public' ] ,
        Field::ELSE => [ Field::PROPERTY => 'basePrice' ] ,
    ],
];
// price: doc.visibility == 'public' ? doc.price : doc.basePrice
```

The condition attribute is **independent** of the projected field — here the value is
`price` but the test reads `visibility`.

## The condition

A condition is a **leaf** (one comparison) or a **group** (leaves combined with logic).

### Leaf forms

| Declared | Meaning | AQL |
|---|---|---|
| `'active'` (string) | truthiness | `TO_BOOL(doc.active)` |
| `[ 'visibility', 'public' ]` | equality | `doc.visibility == 'public'` |
| `[ 'stock', 'gt', 0 ]` | explicit comparator | `doc.stock > 0` |
| `[ FilterParam::KEY => 'status', FilterParam::OP => 'eq', FilterParam::VAL => 'public' ]` | associative form | `doc.status == 'public'` |

The associative form mirrors the flat `?filter=` leaf vocabulary (`FilterParam` `key` / `op`
/ `val` / `alt`) — a condition written for a filter reads the same here.

**Supported comparators** (infix only): `eq`, `ne`, `ge`, `gt`, `le`, `lt`, `in`, `nin`,
`like`, `nlike`, `match`, `nmatch`. Function-form operators (`contains`, `sw`, `ew`,
`regex`, …) are **rejected** — use the flat `?filter=` for those.

**Comparing two attributes** — a value that looks like a document reference is kept raw:

```php
Field::WHEN => [ 'price', 'gt', 'doc.minPrice' ]
// doc.price > doc.minPrice
```

### `alt` on the operands

A leaf may carry an `alt` chain that wraps the compared attribute (left) and/or the value
(right) — the same `"lower"` / `{ key, val }` / `{ key, val:true }` mirror vocabulary as the
flat filters:

```php
Field::WHEN =>
[
    FilterParam::KEY => 'status' ,
    FilterParam::VAL => 'PUBLIC' ,
    FilterParam::ALT => [ 'key' => 'lower' , 'val' => true ] , // mirror both sides
]
// LOWER(doc.status) == LOWER('PUBLIC')
```

> Do not confuse the two `alt` scopes: an `alt` **inside a leaf** wraps the *condition
> operands*; `Field::ALTERS` on the field wraps the *projected value* (see below).

### Groups — AND / OR / NOT

Groups mirror the recursive `?filter=` grammar:

| Declared | AQL |
|---|---|
| `[ [ 'visibility', 'public' ], [ 'stock', 'gt', 0 ] ]` (implicit AND) | `(doc.visibility == 'public' && doc.stock > 0)` |
| `[ 'and', c1, c2 ]` | `(c1 && c2)` |
| `[ 'or', [ 'role', 'admin' ], [ 'owner', 'eq', true ] ]` | `(doc.role == 'admin' \|\| doc.owner == true)` |
| `[ 'not', [ 'anonymized', true ] ]` | `!(doc.anonymized == true)` |
| `[ 'and', [ 'or', c1, c2 ], [ 'active', true ] ]` (nested) | `((c1 \|\| c2) && doc.active == true)` |

Disambiguation: a list starting with `and` / `or` / `not` is a **group**; a list whose
elements are all arrays is an **implicit AND**; a list of scalars is a **single leaf**.

## The `else` branch

Absent `Field::ELSE`, the fallback is `null`. Two forms otherwise:

| Declared | AQL else | Meaning |
|---|---|---|
| `Field::ELSE => 0` | `0` | literal (inlined; `null` / `0` / `'N/A'` …) |
| `Field::ELSE => [ Field::PROPERTY => 'basePrice' ]` | `doc.basePrice` | another document attribute |

## Combining with other options

`Field::WHEN` composes with the other per-field options:

```php
'slug' =>
[
    Field::NAME   => 'title' ,                  // value source ≠ output key
    Field::WHEN   => [ 'published', 'eq', true ] ,
    Field::ALTERS => [ 'trim', 'lower' ] ,      // wraps the THEN value
]
// slug: doc.published == true ? LOWER(TRIM(doc.title)) : null
```

- `Field::ALTERS` decorates the **then** branch (`cond ? ALTERS(value) : else`).
- `Field::NAME` aliases the projected source, independently of the condition attribute.
- `Field::REQUIRES` (permission gating) and `Field::SKINS` (named variants) still apply —
  they decide whether the field is present at all, before the condition is evaluated.

## Filtering the elements of a projected array — `Field::WHERE`

`Field::WHEN` decides **the value** of a scalar field. `Field::WHERE` decides **which
elements** of a projected array (`Filter::MAP`) are returned — a `FILTER` placed in the
nested loop, **between** the `FOR` and the `RETURN`:

```aql
addresses: ( FOR item IN doc.addresses
             FILTER item.region IN @allowedRegions
             RETURN { street: item.street, city: item.city } )
```

Don't confuse the two:

| Marker | Decides | Placed on |
|---|---|---|
| `Field::WHEN` | a field's *value* (ternary) | the default scalar projection |
| `Field::WHERE` | *which elements* of an array are projected (`FILTER`) | a `Filter::MAP` |

`Field::WHERE` reuses the **exact** condition grammar of `Field::WHEN` (leaves, `AND` / `OR`
/ `NOT` groups, `alt`) — compiled against **the array element** (`item`), not against `doc`.

### Comparing against a value known only at query time — `aqlBindRef()`

**The setup.** Each `user` carries an `addresses[]` array, each address has a `region`. A
caller must only see the addresses of **their** allowed regions — and that list is known
**only at query time**, not when the model is written.

A `WHEN` condition **inlines** its values: frozen configuration. Here the value — the list of
regions — exists only at request time. `aqlBindRef('name')` declares "this value is a **bind
variable** `@name`, supplied elsewhere": the name is **validated** (ArangoDB bind rules), **no
value is inlined**, only the `@name` token is emitted.

**1. The model** (static):

```php
use function oihana\arango\db\binds\aqlBindRef ;

'addresses' =>
[
    Field::FILTER => Filter::MAP ,
    Field::WHERE  => [ 'region' , 'in' , aqlBindRef( 'allowedRegions' ) ] ,
    Field::FIELDS => [ 'street' => Filter::DEFAULT , 'city' => Filter::DEFAULT ] ,
]
```

**2. The caller supplies the values** (per request, via the existing `AQL::BINDS` mechanism):

```php
$init[ AQL::BINDS ] = [ 'allowedRegions' => [ 'eu-west' , 'eu-north' ] ] ;
```

**3. The AQL produced** — the `@allowedRegions` token, never the inlined list; its value
travels in the query's **single** `bindVars` map (merged by `AQL::BINDS`). The projection only
**names** the slot; the host **fills** it.

### The bind may also sit on the left

A **boolean** bind can occupy the attribute position — a switch supplied at request time.
`[ aqlBindRef('unrestricted') ]` compiles to `@unrestricted` (a bare token, no `doc.`, no
`TO_BOOL`). Handy for "sees everything, **unless** restricted":

```php
Field::WHERE =>
[ 'or' ,
    [ aqlBindRef( 'unrestricted' ) ] ,                    // → @unrestricted
    [ 'region' , 'in' , aqlBindRef( 'allowedRegions' ) ] , // → item.region IN @allowedRegions
]
// FILTER (@unrestricted || item.region IN @allowedRegions)
```

### Fail-closed by default

Unlike `Field::REQUIRES` (open when no authorizer is present), `Field::WHERE` **closes**:

- a bind bound to an **empty** array → `IN []` → **no element** (the intended behavior);
- a bind **missing** from the final map → the AQL query **fails** (ArangoDB error) → no data.
  A missing bind is **never** reinterpreted as "no filter" (that would be fail-open).

Out-of-scope elements are **never read** from the database: filter, sort and facet can
therefore infer nothing from them. The application wiring (resolving the list, injecting the
binds) happens **outside** the library, in the consumer project.

### Skinned field: the orphan bind is pruned automatically

The situation. The field carrying the `Field::WHERE` is **projected conditionally**: depending
on the active skin (or an explicit `?fields`), it may **not** be rendered. Yet the caller has
already supplied the bind value through `AQL::BINDS` — it cannot reasonably know *in advance*
whether the field will survive. As a result the final query contains **no** `@myBind`
reference, even though the bind is declared. ArangoDB rejects it:

```
bind parameter 'myBind' was not declared in the query
```

The responsibility therefore falls on the layer that executes the query. Right before
execution (`prepareAndExecute()`, the **single** chokepoint every query flows through —
`get()`, `list()`, `count()`, `exist()`, edges…), the library **drops the binds the query
text does not actually reference**. The orphan bind disappears and the query runs.

This pruning is **bounded and safe**:

- it touches **only** the binds declared "optional" — that is, the `aqlBindRef` names
  discovered in the field definitions (`$fields` / `$skinFields`). A bind that is not a field
  `aqlBindRef` is **never** removed;
- an optional bind is dropped **only** when it is absent from the text; if it is referenced it
  is kept (the name is matched against the **whole token**, so `@offers` does not match inside
  `@offersScope`);
- it only ever **removes surplus**: a bind that is referenced-but-missing from the values still
  fails exactly as before. The only thing lost is ArangoDB's protection against "extra" binds —
  pointless for library-built queries.

Nothing to wire on the host side: the source of truth is the `aqlBindRef` you already wrote in
the field. The `prepareAndExecute( …, $optionalBinds )` parameter (4th position) remains
available to **force** the list, or to **disable** the pruning by passing `[]`.

## Security

A `Field::WHEN` condition is compiled **inline**; a `Field::WHERE` one may additionally
**reference a bind**. Both are safe by construction:

- **Attribute names** (condition operands and an attribute-valued `else`) are validated by
  `assertAttributeName()` — any character able to break out of a `doc.<attr>` accessor is
  rejected with a `ValidationException`.
- **Literal values** are developer-declared in the field definition (never request input —
  those go behind binds in `?filter=`), inlined and escaped by `aqlValue()`.
- A **bind reference** (`aqlBindRef('name')`) inlines nothing: the **name** is validated by
  `assertBindVariable()`, and only the `@name` token is emitted. The **value** is supplied to
  the query via `AQL::BINDS` — so never concatenated into the AQL text, whatever it holds.
- **Permission gating** covers not only the field that *carries* the condition (already gated
  by its own `Field::REQUIRES`) but also the fields the condition **reads** (`Field::WHEN` /
  `Field::WHERE`, and an attribute-valued `else` branch). If one is hidden from reading
  (`Field::REQUIRES` denied for that user), **the whole conditional field is dropped** from
  the projection — otherwise the presence/absence of its value (or the `else` branch) would
  betray the masked field (inference oracle). Fail-open: a read field **without**
  `Field::REQUIRES`, absent from the projection, or with no authorizer, leaves the
  conditional field intact.

## Generated AQL — reference

```
price : (TO_BOOL(doc.active) && LOWER(doc.status) == 'public') ? LOWER(TRIM(doc.price)) : doc.basePrice
        └─────────────── condition ───────────────┘            └──── then (+ALTERS) ───┘   └── else ──┘
```

See also: [AQL helpers](helpers.md) · [Field projection](../projection.md).
