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

> Applies to the **default scalar projection** and to the two projections that fabricate a
> value with no guard of their own: a rebuilt sub-document
> (`Filter::DOCUMENT`, see [Guarding a sub-document](#guarding-a-sub-document--fieldnullable))
> and a rebuilt link
> (`Filter::URL`, see [The link, only when there is a key](#the-link-only-when-there-is-a-key--fieldwhen-on-a-filterurl)).
> `Field::WHEN` on any other typed/structural filter (`EDGES`, `JOINS`, `MAP`, …) throws an
> `UnsupportedOperationException`: those filters carry their own shape and their own guard,
> there is nothing to add.

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
| `Field::ELSE => 0` | `0` | literal (inlined; `null` / `0` / `'unknown'` …) |
| `Field::ELSE => [ Field::PROPERTY => 'basePrice' ]` | `doc.basePrice` | another document attribute |

### An ambiguous literal — `betweenQuotes()`

A string literal is quoted automatically… **unless it already looks like AQL**. That is
deliberate: it is what lets you write `[ 'price' , 'gt' , 'doc.minPrice' ]` and compare two
attributes rather than an attribute to some text. But a few real-world literals fall into the
same net — `N/A` has the shape of a *document handle* (`collection/key`), and so does `en/US`.

To lift the ambiguity, say so explicitly with `betweenQuotes()`:

```php
use function oihana\core\strings\betweenQuotes ;

Field::ELSE => betweenQuotes( 'N/A' ) ,   // →  : 'N/A'
Field::ELSE => 'N/A' ,                    // →  : N/A   ⛔
```

Without it, ArangoDB reads `N/A` as code and **rejects the whole query** — including for the
rows that never take the `else` branch, since name resolution happens at plan time, not at
execution time:

```
RETURN { p: true  ? 1 : N/A }    → ERROR 1203: collection or view not found: A
RETURN { p: false ? 1 : N/A }    → ERROR 1203: collection or view not found: A
RETURN { p: false ? 1 : 'N/A' }  → OK  [ { "p": "N/A" } ]
```

The failure is therefore loud and immediate, never a wrong value slipping through unnoticed.
The rule fits in one sentence: **a literal containing a `/` or a `.` is declared with
`betweenQuotes()`**. The others (`'unknown'`, `'not set'`, `0`, `null`) need nothing.

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

## Guarding a sub-document — `Field::NULLABLE`

**The situation.** A `Filter::DOCUMENT` does not read a sub-document, it **rebuilds** it
attribute by attribute. That is what lets a `url`, for instance, be recomputed on read
instead of stored:

```php
'thing' =>
[
    Field::FILTER => Filter::DOCUMENT ,
    Field::FIELDS =>
    [
        '_key' => [] ,
        'name' => [] ,
        'url'  => [ Field::FILTER => Filter::URL , Field::PATH => '/things' ] ,
    ] ,
]
```

That rebuild was **never guarded**. When the source attribute is missing, every line reads
an attribute of an object that does not exist — which AQL resolves to `null` without
error — and the object is emitted all the same. An empty slot came back **dressed**:

| The stored document | What came out |
|---|---|
| `{ "_key": "u1", "name": "Alice", "thing": { "_key": "t9" } }` | `{ "thing": { "_key": "t9", "name": null, "url": "https://base/things/t9" } }` |
| `{ "_key": "u2", "name": "Bob" }` — no `thing` | `{ "thing": { "_key": null, "name": null, "url": "https://base/things/" } }` |

The second row is the problem: `url` is `"https://base/things/"`, an address that leads
nowhere, and on the consumer side `if (x.thing)` is **true** while there is nothing there.
You had to know to write `x.thing?._key`, which nothing announced.

**The remedy.** One line, stating the intent « no source, no object »:

```php
'thing' =>
[
    Field::FILTER   => Filter::DOCUMENT ,
    Field::NULLABLE => true ,            // ← the only added line
    Field::FIELDS   => [ … same … ] ,
]
```

```aql
thing:IS_OBJECT(doc.thing) ? {_key:doc.thing._key, name:doc.thing.name, url:CONCAT('https://base/things','/',doc.thing._key)} : null
```

| The stored document | What comes out |
|---|---|
| `{ …, "thing": { "_key": "t9" } }` | `{ "thing": { "_key": "t9", "name": null, "url": "https://base/things/t9" } }` — unchanged |
| `{ … }` — no `thing` | `{ "thing": null }` |

The object inside the braces has not moved by a single character: it was merely put behind
a guard.

> **Why `IS_OBJECT()` and not `!= null`.** An attribute that *exists* but is not an
> object — a string, a number — rebuilds the very same object of nulls. The test is
> therefore a **type** test, as everywhere else in the library (`Filter::ARRAY` tests
> `IS_ARRAY`, `Filter::EDGE` tests `IS_OBJECT`).

### The free condition — `Field::WHEN` on a `Filter::DOCUMENT`

`Field::NULLABLE` is nothing but a condition written in advance. When the guard has to be
something other than « the source exists », it is written with the condition grammar
described above:

```php
'contact' =>
[
    Field::FILTER => Filter::DOCUMENT ,
    Field::WHEN   => [ 'visibility' , 'public' ] ,
    Field::FIELDS => [ 'email' => [] , 'telephone' => [] ] ,
]
// contact: doc.visibility == 'public' ? {email:doc.contact.email, telephone:doc.contact.telephone} : null
```

The false branch is chosen as on a scalar (`Field::ELSE`, default `null`), and the two
guards compose with `&&` — no need to restate existence by hand:

```php
    Field::NULLABLE => true ,
    Field::WHEN     => [ 'visibility' , 'public' ] ,
// contact: (IS_OBJECT(doc.contact) && doc.visibility == 'public') ? { … } : null
```

**The condition reads on the parent document**, never on the rebuilt sub-document:
`doc.visibility`, not `doc.contact.visibility`. This is not an implementation detail — it
is what makes the authorization gate described in [Security](#security) apply verbatim: it
guards the attributes read by a condition against the projection of the **current** level.
If `visibility` carries a denied `Field::REQUIRES`, the whole `contact` field disappears
instead of becoming an oracle on `visibility`.

### Nesting

Each level carries its own guard; they do not interfere, the outer ternary never evaluating
the inner object when it is false:

```php
'thing' =>
[
    Field::FILTER   => Filter::DOCUMENT ,
    Field::NULLABLE => true ,
    Field::FIELDS   =>
    [
        'name'  => [] ,
        'owner' => [ Field::FILTER => Filter::DOCUMENT , Field::NULLABLE => true , Field::FIELDS => [ 'name' => [] ] ] ,
    ] ,
]
// thing: IS_OBJECT(doc.thing) ? {name:doc.thing.name, owner:IS_OBJECT(doc.thing.owner) ? {name:doc.thing.owner.name} : null} : null
```

### Where the marker applies — and where it throws

`Filter::DOCUMENT` is the **only** filter that rebuilds an **object** with no guard of its
own. The others already guard themselves, each with the test that suits its shape — placing
`Field::NULLABLE` on one of them would be a silent no-op, so it is a definition error and
throws an `UnsupportedOperationException`:

| Filter | Rebuilds | Missing source | `Field::NULLABLE` |
|---|---|---|---|
| `Filter::DOCUMENT` | an object | an object of `null`s | ✅ `IS_OBJECT()` |
| `Filter::BOOL` | a boolean | `false` — an answer to a question never asked | ✅ `!= null`, [below](#guarding-a-cast--fieldnullable-on-a-filterbool-or-a-filternumber) |
| `Filter::NUMBER` | a number | `0` — « free » and « no price » collapsed | ✅ `!= null`, [below](#guarding-a-cast--fieldnullable-on-a-filterbool-or-a-filternumber) |
| `Filter::ID` | nothing — it projects the key as stored (`id: doc._key`) | `null` | ⛔ throws — nothing is fabricated |
| `Filter::MAP` | an array | `[]` — `IS_ARRAY()` already placed | ⛔ throws |
| `Filter::WRAP` | an object | moot: it projects the current reference, which exists by construction | ⛔ throws |
| `Filter::EDGE` / `Filter::JOIN` | an object | `null` — `IS_OBJECT()` already placed | ⛔ throws |
| `Filter::URL` | a link | a truncated address | ⛔ throws — its guard is `Field::WHEN`, [next section](#the-link-only-when-there-is-a-key--fieldwhen-on-a-filterurl) |

The last row is the one to read twice. A `Filter::URL` fabricates without a guard just like
a `Filter::DOCUMENT` does — but from a **scalar key**, not from an object, so `IS_OBJECT()`
would never hold there and the field would simply never be emitted. `Field::NULLABLE` keeps
its single meaning; the url is guarded with a condition instead.

> **One caveat, worth knowing.** The `Field::EDGES` / `Field::JOINS` declared **under** a
> guarded sub-document still emit their `LET` upstream, even when the guard yields `null`.
> The query stays correct; it is not made faster. This is structural: a `LET` cannot be
> conditioned in AQL.

**Backward compatibility.** Without the marker the emitted AQL is **byte for byte** the one
from before: no `IS_OBJECT`, no ternary, not one extra space. Every existing projection is
unchanged, and a test pins it.

## Guarding a cast — `Field::NULLABLE` on a `Filter::BOOL` or a `Filter::NUMBER`

**The situation.** `TO_BOOL()` answers even when nothing was asked. A document that says
nothing about the attribute comes back saying « no », exactly like the one that really
stores `false` — and from the response, the two are indistinguishable. Measured on a real
server:

| The stored document | What came out |
|---|---|
| `{ "active": true }` | `{ "active": true }` |
| `{ "active": false }` | `{ "active": false }` |
| `{ }` — nothing about `active` | `{ "active": false }` ← **the same answer** |

**The remedy.** The same marker as on a sub-document, with the test the shape calls for:

```php
'active' =>
[
    Field::FILTER   => Filter::BOOL ,
    Field::NULLABLE => true ,        // ← the only added line
]
// active:doc.active != null ? TO_BOOL(doc.active) : null
```

`Field::ELSE` says what to answer instead of `null` — the historical `false`, for instance,
but declared rather than invented:

```php
    Field::NULLABLE => true ,
    Field::ELSE     => false ,
// active:doc.active != null ? TO_BOOL(doc.active) : false
```

> **Why `!= null` and not `IS_BOOL()`.** On a rebuilt object the guard is a **type** test —
> here it must not be. `TO_BOOL()` exists precisely to accept what is *not* a boolean: a
> document storing `1`, `"yes"` or `"on"` counts as `true` today, and must keep counting.
> `IS_BOOL()` would have made all of them abstain at once — repairing one case and breaking
> three. The question asked is only « is the attribute there? », so that is the only
> question emitted. A live test pins those documents as still `true`.

### The same on a number

`Filter::NUMBER` fabricates exactly the same way — and the ambiguity it creates is easier to
feel: the offer that really is **free** and the one we simply **have no price for** both come
back as `0`.

```php
'price' => [ Field::FILTER => Filter::NUMBER , Field::NULLABLE => true ] ,
// price:doc.price != null ? TO_NUMBER(doc.price) : null
```

| The stored document | Without the marker | With it |
|---|---|---|
| `{ "price": 19.9 }` | `19.9` | `19.9` |
| `{ "price": 0 }` — really free | `0` | `0` — a stored `0` stays a `0` |
| `{ }` — no price | `0` ← **the same answer** | `null` |
| `{ "price": "42" }` — a string | `42` | `42` — still converted |

The same reasoning holds for the test: `IS_NUMBER()` would have dropped the last row, which
`TO_NUMBER()` exists to accept. A live case pins it.

> **`Filter::ID` no longer converts at all.** It used to emit `TO_NUMBER(doc._key)`, which
> is a different problem — the key is **present**, it is the conversion that harms: every
> non-numeric key became `0`, so all those documents shared one identifier, leading zeros
> were dropped (`"007"` → `7`) and precision was lost past 2^53. It now emits `id: doc._key`
> and returns the key as stored. Nothing is fabricated any more, so `Field::NULLABLE` stays
> refused there — an absent source already yields `null` on its own.

**Backward compatibility.** Without the marker the emitted AQL is unchanged **byte for
byte** — no test, no ternary. Every existing projection keeps answering `false` and `0`.

## The link, only when there is a key — `Field::WHEN` on a `Filter::URL`

**The situation.** A `Filter::URL` does not read a stored address either — it **rebuilds**
one, by concatenating a route and the document key:

```php
'url' => [ Field::FILTER => Filter::URL , Field::PATH => '/things' ] ,
// url:CONCAT('https://base/things','/',doc._key)
```

Now, AQL **drops** the null arguments of a `CONCAT()`. So a document with no key does not
come back without a url: it comes back with an address that leads nowhere, and nothing in
the response says so.

This is not a theoretical corner. A sub-document projection rebuilds **frozen copies**:
some of them come from a record and carry its key, so their link is legitimately recomputed
on read; others are values typed by hand, which have no record behind them and therefore
**no key at all**. Both live in the same field, told apart by a discriminant they carry.
Measured on a real server, side by side:

| The stored copy | What came out |
|---|---|
| `{ "_key": "t9", "additionalType": "Place", "name": "Widget" }` | `{ "name": "Widget", "url": "/things/t9" }` |
| `{ "additionalType": "Text", "name": "Hand-typed" }` — no key | `{ "name": "Hand-typed", "url": "/things/" }` |
| `{ "_key": "", "additionalType": "Place", … }` — an empty key | `{ "name": "Empty key", "url": "/things/" }` |

**The remedy.** The same condition grammar, on the url itself — the object around it is not
guarded, only the link gives up:

```php
'url' =>
[
    Field::FILTER => Filter::URL ,
    Field::PATH   => '/things' ,
    Field::WHEN   => [ '_key' ] ,        // ← the only added line
]
```

```aql
url:TO_BOOL(doc._key) ? CONCAT('https://base/things','/',doc._key) : null
```

| The stored copy | What comes out |
|---|---|
| `{ "_key": "t9", … }` | `{ "name": "Widget", "url": "/things/t9" }` — unchanged |
| no key | `{ "name": "Hand-typed", "url": null }` |
| an empty key | `{ "name": "Empty key", "url": null }` |

> **Why a one-element condition.** `[ '_key' ]` is a **truthiness** leaf (`TO_BOOL()`), not
> an equality. It is what covers the empty key as well as the absent one — both produce the
> very same truncated link, and a `!= null` test would only have caught the second.

### Reading the discriminant of the copy

The condition is compiled against **the reference the projection itself reads from**. For a
url projected inside a sub-document, that reference *is* the sub-document — so a
discriminant carried by the copy decides, without a word about the parent:

```php
'thing' =>
[
    Field::FILTER => Filter::DOCUMENT ,
    Field::FIELDS =>
    [
        'name' => [] ,
        'url'  => [ Field::FILTER => Filter::URL , Field::PATH => '/things' , Field::WHEN => [ 'additionalType' , 'Place' ] ] ,
    ] ,
]
// thing:{name:doc.thing.name, url:doc.thing.additionalType == 'Place' ? CONCAT('/things','/',doc.thing._key) : null}
```

This is also what makes the authorization gate apply verbatim, as on a `Filter::DOCUMENT`:
the attributes a condition reads are checked against the projection of the **current**
level, so a url whose condition reads a denied field disappears whole rather than becoming
an oracle on it.

> **A limit, measured rather than assumed.** A discriminant says what a copy *is*, not
> whether it can be addressed. A copy that declares itself a `Place` and still carries no
> usable key comes back with the truncated link — guarding on the type is a different
> question from guarding on the key. When both matter, `Field::WHEN` takes an `and` group.

**Backward compatibility.** Without the marker the emitted AQL is unchanged **byte for
byte**, on the plain route as on the discriminant-routed one (`Field::PATHS`) — no ternary,
no test. Two tests pin it.

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

| Marker | Decides | Placed on | Compiled against |
|---|---|---|---|
| `Field::WHEN` | a field's *value* (ternary) | the default scalar projection, a `Filter::DOCUMENT` or a `Filter::URL` | the reference of the level being projected — `doc` for a sub-document, the sub-document itself for a url nested in one |
| `Field::NULLABLE` | whether the fabricated value is emitted (`IS_OBJECT` of the source on a `Filter::DOCUMENT`, `!= null` on a `Filter::BOOL` / `Filter::NUMBER`) | a `Filter::DOCUMENT`, a `Filter::BOOL`, a `Filter::NUMBER` | `doc` (the **parent**) |
| `Field::WHERE` | *which elements* of an array are projected (`FILTER`) | a `Filter::MAP` | the element (`item`) |
| `AQL::WHERE` | *which vertices* a relation projects (`FILTER`) | an edge **definition** | the traversed vertex (`vertex`) |

`Field::WHERE` reuses the **exact** condition grammar of `Field::WHEN` (leaves, `AND` / `OR`
/ `NOT` groups, `alt`) — compiled against **the array element** (`item`), not against `doc`.

> **The same question, one step further: `AQL::WHERE`.** What `Field::WHERE` does for an array
> **embedded** in the document, `AQL::WHERE` does for a **relation** (`Filter::EDGE` / `EDGES` /
> `EDGES_COUNT`): it restricts the traversed vertices, with that same grammar, that same
> `aqlBindRef()` support and that same fail-closed contract. The difference is the seat —
> `Field::WHERE` is declared on a **projection entry**, `AQL::WHERE` on a **relation definition**,
> where it holds for every entry point at once. The pair mirrors `Field::REQUIRES` (entry) and
> `AQL::REQUIRES` (definition). Details in
> [Edge and join projection](../edges-joins-projection.md#restricting-the-projected-vertices--aqlwhere).

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
  discovered in the model's declarations: the projections (`$fields` / `$skinFields`) **and the
  relation registries** (`$edges` / `$joins`). A bind that is not a declared `aqlBindRef` is
  **never** removed;
- an optional bind is dropped **only** when it is absent from the text; if it is referenced it
  is kept (the name is matched against the **whole token**, so `@offers` does not match inside
  `@offersScope`);
- it only ever **removes surplus**: a bind that is referenced-but-missing from the values still
  fails exactly as before. The only thing lost is ArangoDB's protection against "extra" binds —
  pointless for library-built queries.

Nothing to wire on the host side: the source of truth is the `aqlBindRef` you already wrote in
the field. The `prepareAndExecute( …, $optionalBinds )` parameter (4th position) remains
available to **force** the list, or to **disable** the pruning by passing `[]`.

The **relation registries** matter as much as the projections. An edge or join definition is a
declaration tree in its own right: it can carry a bind, either in its own sub-projection
(`AQL::FIELDS`) or in a definition-level predicate. And a relation is projected conditionally
too — a skin can drop it entirely — so its bind is left orphaned in exactly the same way. This
is why the discovery reads all four sources, not just the two projection trees.

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
