# Sorting (`?sort=` and `?near=`)

A list query does more than **restrict** (see [Search & filtering](search-and-filtering.md)): it **orders**. This page covers the two URL-facing sort levers — `?sort=` (field ordering) and `?near=` (geographic distance ordering) — and above all the **guardrail** that decides *what* a client is allowed to sort on.

## The principle: fail-closed

**The analogy.** Like projection (`fields`) and filters, sorting has a doorkeeper. The model holds the **guest list** — the fields declared sortable in `AQL::SORTABLE`. A sort key that is not on the list does not get in: it is **silently dropped**. And a model that holds **no** list lets you sort on **nothing** — never "everything by default".

> This is the point to remember: a missing `AQL::SORTABLE` (`null`) means **nothing is sortable**, not "everything is sortable". The client never picks a field name the model has not explicitly opened.

## The `?sort=` grammar

`?sort=` is a **comma-separated list of keys**; a leading `-` flips a key to descending.

```
?sort=name,-created   →   SORT doc.name ASC, doc.created DESC
```

Each key is resolved against the `AQL::SORTABLE` whitelist (URL key → AQL field). A key outside the whitelist is **dropped** (no error — it simply disappears from the `SORT` clause).

## Declaring sortable fields — `AQL::SORTABLE`

The whitelist resolves each `?sort=` key to an AQL field. Three interchangeable notations are accepted and **may be mixed in the same array**:

```php
// Indexed shorthand — the URL key equals the field (the common case):
AQL::SORTABLE => [ Prop::_FROM , Prop::_TO , Prop::CREATED , Prop::MODIFIED ]

// Indexed alias — the public key differs from the AQL field (?sort=name → doc.givenName):
AQL::SORTABLE => [ [ Prop::NAME => Prop::GIVEN_NAME ] , Prop::CREATED ]

// Associative (legacy) — still supported, unchanged:
AQL::SORTABLE => [ Prop::CREATED => Prop::CREATED , Prop::NAME => Prop::GIVEN_NAME ]
```

- The pair direction is always `[ urlKey => fieldPath ]`, identical across the three forms.
- A field value may be a multi-segment path (`[ 'address', 'city' ]` → `address.city`), in alias form too.

Normalisation (`oihana\arango\models\helpers\normalizeSortable()`) folds any of the three forms into the canonical `urlKey => fieldPath` map, once at construction. It is **idempotent** and **backward-compatible**: an existing associative map is returned untouched.

## The default sort goes through the whitelist too

`AQL::SORT_DEFAULT` sets the sort applied when no `?sort=` is given. It is written in **the same grammar** as `?sort=` (e.g. `'-created'`) and travels through **the same doorkeeper**.

> **The situation.** A model wants to sort by `created` by default but declares no `SORTABLE`.

```php
AQL::SORT_DEFAULT => Prop::CREATED ,   // and no AQL::SORTABLE
```

Since the whitelist is empty (fail-closed), the default `created` key is dropped as well: **the model sorts nothing**. The rule is single and exception-free: *everything named — by the client or by the default — must be in `SORTABLE`.*

```php
AQL::SORTABLE     => [ Prop::CREATED , Prop::NAME ] ,
AQL::SORT_DEFAULT => descKey( Prop::CREATED ) ,        // '-created' — the key is whitelisted: OK
```

## Sorting through a relation

**The situation.** An article's author is not one of its fields: it lives in
another document, at the end of an edge. `?sort=author` used to be impossible —
the sort compiler knew a single shape, `doc.<path>`, a **stored** path and
nothing else.

It is possible when the relation is **projected**, and the reason is worth
knowing: projecting a `Filter::EDGE` field emits a `LET`, and the compiled query
places every `LET` **before** the `SORT`. Ordering on the related document is
therefore a matter of *naming that variable*, not of traversing a second time.
One traversal serves both the projection and the order.

Three declarations work together:

```php
AQL::FIELDS =>
[
    'title'  => [] ,
    'author' =>
    [
        Field::FILTER => Filter::EDGE ,   // ① singular relation
        Field::UNIQUE => 'authorRef' ,    // ② pin the LET variable
    ] ,
] ,

AQL::EDGES =>
[
    'author' =>
    [
        AQL::MODEL     => $articlesAuthors ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::FIELDS    => [ '_key' => [] , 'name' => [] ] ,
    ] ,
] ,

AQL::SORTABLE =>
[
    'title' ,
    'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name' ] ,  // ③
] ,
```

```
?sort=title,-author
```
```aql
FOR doc IN @@articles
  LET authorRef = ( FOR v IN OUTBOUND doc articles_authors RETURN … )
  SORT doc.title ASC, FIRST(authorRef).name DESC
  RETURN { title: doc.title, author: … }
```

- **`AQL::EDGE` names the projected field**, not the edge collection. That is
  what lets the sort find the variable the projection already created.
- **`Field::PATH` names the field of the related document**, and may be a nested
  path (`[ 'address', 'city' ]` → `FIRST(authorRef).address.city`).
- **The relation must be projected**: the library never builds a traversal just
  to order. If you sort on it, you return it.
- **`Field::UNIQUE` is required.** Without it the `LET` variable is a generated
  random name (`author_e1626906831`), which no declaration can designate.

### What is refused, and how loudly

The frontier is the one the whole library draws: a **declaration** the model's
author must fix is refused loudly; a **client key** is dropped in silence.

| Situation | Reaction |
|---|---|
| The named field is not projected | **refused** (`500`) — there is no `LET` to name |
| The relation is plural (`Filter::EDGES`) | **refused** (`500`) — which of the related documents would decide? |
| No `Field::UNIQUE` on the relation | **refused** (`500`) — the variable cannot be designated |
| No `Field::PATH` on the entry | **refused** (`500`) — nothing says what orders the list |
| `?sort=` names an unknown key | **dropped**, silently — unchanged contract |
| The permission refuses the key | **dropped**, silently — no sort oracle |

**Permission** works as everywhere else: an explicit `Field::REQUIRES` on the
`SORTABLE` entry wins, otherwise the subject of the **projected relation** is
inherited. What you cannot read, you cannot order by — otherwise the order
betrays it.

> **With `?group=`**, a relational criterion is inert: after a `COLLECT` the
> document variables no longer exist, and the grouped sort only accepts the
> variables the `COLLECT` emits. The key is dropped like any other non-group key.

## Sorting a multilingual label

**The situation.** A catalogue keeps its labels per locale:
`{ "name": "Zinc-3000", "alternateName": { "fr": "Aspirateur", "en": "Hoover" } }`.
Declaring `'label' => 'alternateName.fr'` orders the translated documents nicely —
and piles up **in front, in an arbitrary order**, every document with no French:
they order on `null`. The query succeeds, the page renders, the list is wrong.

An entry may therefore aim at a locale and **fall back**:

```php
AQL::FIELDS =>
[
    'name'          => [] ,
    'alternateName' => Filter::TRANSLATE ,   // ① the translations object
] ,

AQL::SORTABLE =>
[
    'name' ,
    'label' =>
    [
        Field::PATH         => 'alternateName' , // ② the path of the object
        Field::ELSE         => 'name' ,          // ③ the last resort (optional)
        Field::DEFAULT_LANG => 'fr' ,            // ④ the fallback locale (optional)
    ] ,
] ,
```

`?sort=label&lang=en` then compiles to:

```aql
SORT NOT_NULL(doc.alternateName["en"], doc.alternateName["fr"], doc.name) ASC
```

**What marks the entry as multilingual** follows the two steps permission already
follows — inherited first, explicit second:

| Form | When |
|---|---|
| **inherited** — ① the field is declared `Filter::TRANSLATE` in `AQL::FIELDS` | the common case: the field is projected |
| **explicit** — `Field::FILTER => Filter::TRANSLATE` on the entry itself | the field is sortable but **not** projected |

⚠ Inheritance reads a **root** field. A translated field nested inside a structural
one is not walked into: declare `Field::FILTER` on the entry. A miss is not a hole —
the entry falls back to the stored path, which is what it always was.

### The fallback locale, and who declares it

Three places, from the most local to the most general; **the first that answers wins**:

| # | Where | Key |
|---|---|---|
| 1 | the sort entry | `Field::DEFAULT_LANG` |
| 2 | the model | `Arango::DEFAULT_LANG` at construction (or the container's `defaultLang` entry) |
| 3 | the host, pushed per call | `Arango::DEFAULT_LANG` in the init |

⚠ **The model outranks the host, on purpose.** What the host pushes is a *default*,
and a default must never override an explicit declaration — otherwise a model would
change behaviour depending on which site loads it, without a line of it moving.

⚠ Not to be confused with `Arango::LANG` (`?lang=`), the **requested** locale: that
one is an *instruction*, and it wins over all three. Both travel in the same init.

### Case by case

A French site (`defaultLang` = `fr`):

| Request | Emitted expression |
|---|---|
| `?sort=label&lang=en` | `NOT_NULL(doc.alternateName["en"], doc.alternateName["fr"], doc.name)` |
| `?sort=label&lang=fr` | `NOT_NULL(doc.alternateName["fr"], doc.name)` — **deduplicated** |
| `?sort=label` | same: no requested locale, the fallback answers |
| `?sort=label&lang=all` | same: `all` widens what is returned, it does not unplug the sort |
| `?sort=label&lang=zz` | same: the locale is filtered upstream by the controller whitelist |
| without `Field::ELSE` | `NOT_NULL(doc.alternateName["en"], doc.alternateName["fr"])` |
| no locale, no fallback, no `ELSE` | `doc.alternateName` — the stored path, like any ordinary entry |

`Field::ELSE` is **optional**: without it, a document with no translation at all
orders on `null`, exactly where it ordered before. And a declaration left without a
single link never drops the criterion in silence: it degrades to the stored path.

### The guards

A locale names an **attribute** of the translations object, and an attribute name
cannot be a bind parameter: it is written verbatim into the query. It is therefore
validated, and the blame follows where it came from — the frontier
`assertAttributeName()` already draws:

| What is wrong | Answer |
|---|---|
| an unreadable `?lang=` (outside the controller, which already filters) | `400` — the caller wrote something unreadable |
| `Field::DEFAULT_LANG => 'fr_FR'` (declared) | `500` — no caller can fix it |
| `Field::ELSE => 'name || 1==1'` | `500` — same reason |
| `Field::ELSE` naming a forbidden field | link **dropped** from the chain (no oracle) |

⚠ The locale is reached through a **bracket** accessor, uniformly
(`doc.alternateName["fr"]`) rather than a dotted one. A dashed tag (`pt-BR`) reads as
a subtraction in dot notation: one shape for every locale beats a shape that depends
on the locale.

⚠ **No index can serve this expression**: ArangoDB sorts the filtered set in memory.
Painless over a few thousand documents; on a large paginated collection, the remedy is
an attribute computed at write time (a *computed value*), which is indexable.

## Sort permission

Whitelisting is not always enough. A field may be **hidden from reading** by a permission (`Field::REQUIRES` in the projection): if it stays sortable, the order of the results betrays its value. That is the **sort oracle** — sorting on `salary` without the right to read it, and guessing who earns the most just by looking at the order.

Sorting therefore closes at the same place as reading. The permission resolves two ways.

### Inherited (the common case)

**The situation.** The `salary` field is already gated in the projection; we want it sortable without repeating the permission.

```php
public array  $fields   = [ Prop::NAME => true , Prop::SALARY => [ Field::REQUIRES => 'hr:read' ] ] ;
public ?array $sortable = [ Prop::NAME , Prop::SALARY ] ;   // just the list
```

When `?sort=salary` arrives, sorting **inherits the permission of the target field in `$fields`, at the resolved path** — not only at the root. An alias to a deep path (`'salary' => 'address.salary'`) inherits the `Field::REQUIRES` of the **exact sub-field** `address.salary`, exactly like `?groupBy=` and `?bounds=` (via `isPathAuthorized`, which descends `Field::FIELDS` and strips `[*]`). *"What you cannot read, you cannot sort on"* — automatically, with no duplicate declaration, and without hitting the wrong field (never the URL key's homonym).

| User **with** `hr:read` | User **without** `hr:read` |
|---|---|
| `?sort=salary` → `SORT doc.salary ASC` | `?sort=salary` → key **dropped**, no sort on that field |

### Explicit (a sort-only field, or a sort-specific rule)

**The situation.** A sortable field that does **not** exist in the projection — there is no permission to inherit. Declare it directly on the `SORTABLE` entry.

```php
AQL::SORTABLE =>
[
    Prop::NAME ,
    'rank' => [ Field::PATH => 'internal.rank' , Field::REQUIRES => 'staff:read' ] ,
] ,
```

The entry carries its own field (`Field::PATH` → `doc.internal.rank`) and its own permission (`Field::REQUIRES`). A permission written here **overrides** the one inherited from `$fields`.

> **Resolution rule.** The explicit permission on the `SORTABLE` entry wins; otherwise it is inherited from the **target field in `$fields`, at the resolved path** (depth included — never the URL key's homonym); otherwise no permission (the field sorts freely). No permission, or no authorizer injected → free sort (*fail-open* — exactly the `fields` semantics).

## The synthetic `distance` and `score` keys

Two sort keys name not a field but a **computation**, and are resolved **upstream** of the whitelist — so they sort even without a `SORTABLE`:

- **`distance`** — driven by `?near=` (see below). Without a `?near=` anchor, `?sort=distance` is dropped.
- **`score`** — the relevance of a View search (`?search=` on a declared View). See [View search](search/overview.md). An active search alone sorts by `score` descending by default (most relevant first).

## Distance sorting (`?near=`)

Unlike the three filtering levers, which **restrict**, `?near=` **orders**: it ranks the list from nearest to farthest from a geographic point. It does not filter — pair it with a [`geo` filter](filter.md#distance-operator-geolocation) to bound a radius.

```
?near={"key":"geo","latitude":48.8566,"longitude":2.3522}
# → SORT DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) ASC
```

`?near=` provides the **anchor point** (a Schema.org `GeoCoordinates` attribute, short aliases `lat`/`lng`/`lon` accepted) and exposes the **synthetic `distance` sort key** driven by `?sort=` — which stays the **single ordering authority**:

| Request | Sort |
|---|---|
| `?near=…` alone | `distance` ASC (default, nearest first) |
| `?near=…&sort=-distance` | farthest first |
| `?near=…&sort=distance,name` | distance then name (you pick the priority) |
| `?near=…&sort=name` | name only — distance **not** appended (explicit `?sort` decides) |
| `?sort=distance` without `?near=` | dropped (no anchor) |

### The geo key is a sort dimension — so it is whitelisted

**The situation.** The `"key":"geo"` of the payload names the geo field the distance is measured from. It is a field the client picks: it passes **the same doorkeeper** as any sort key.

The geo key must therefore be **declared in `AQL::SORTABLE`**, and may be **permission-gated** like the rest:

```php
// Distance sorting open to everyone:
AQL::SORTABLE => [ Prop::NAME , 'geo' ] ,

// Distance sorting restricted (sensitive location):
AQL::SORTABLE => [ Prop::NAME , 'geo' => [ Field::PATH => 'geo' , Field::REQUIRES => 'geo:read' ] ] ,
```

| Geo key whitelisted (and allowed) | Geo key absent from `SORTABLE`, or refused |
|---|---|
| `?near={"key":"geo",…}` → distance sort | key **dropped**, no distance sort |

Typical combination — the 10 **nearest** museums within 5 km:

```
?near={"key":"geo","latitude":48.8566,"longitude":2.3522}
&filter=[{"key":"type","val":"museum"},{"key":"geo","op":"distance","val":{"latitude":48.8566,"longitude":2.3522},"max":5000}]
&limit=10
```

`DISTANCE` operates on two scalars → **index-accelerated** sort with a two-field [`GeoIndex`](../clients/indexes.md). Coordinates are bound only when a `distance` criterion is actually emitted (never an unused bind). See the [geospatial functions](../aql/aql-functions-geo.md).

## Limits & migration

- **No `SORTABLE` = nothing sorts.** A model that relied on the old "open mode" (sorting without declaring `SORTABLE`) must now declare its keys. Otherwise `?sort=` and `SORT_DEFAULT` produce nothing.
- **`SORT_DEFAULT` must name whitelisted keys.** The default sort goes through the same doorkeeper as the client.
- **The `?near=` geo key must be in `SORTABLE`.** A model exposing `?near=` declares its geo field (`'geo'`, or a `Field::REQUIRES` definition to gate it). Without it, distance sorting stops.
- **An invalid client key is dropped, never an exception.** A sort key (or a geo key) outside the whitelist, or refused by permission, is simply dropped — no injection possible, no crash. A faulty **declaration** is the other side of that frontier: a relational entry that cannot be honoured throws, because only the model's author can fix it.
- ⚠ **`Arango::SORT` also accepts an array, and that form skips both doorkeepers.** A string is what a client sends (`?sort=name`) and it goes through the `SORTABLE` whitelist and the permission gate. An **array** is copied verbatim into the `SORT` clause:

  ```php
  $model->list( [ Arango::SORT => 'salary' ] ) ;              // whitelist + permission
  $model->list( [ Arango::SORT => [ 'doc.salary DESC' ] ] ) ; // neither
  ```

  No URL can produce an array — query parameters are always strings — so this is a **server-side facility**, not an exposed hole. But it is the one path where a sort criterion reaches the query ungated: never build that array from request data, or both guards fall at once. For a relation, use the declaration above instead.

## See also

- [Search & filtering](search-and-filtering.md) — the three levers that **restrict** the list.
- [Field projection](../projection.md) — `Field::REQUIRES`, skins and the permission system sorting inherits from.
- [Filters `?filter=`](filter.md) — including the [`distance` operator](filter.md#distance-operator-geolocation) that **bounds** a radius.
- [View search (ArangoSearch)](search/overview.md) — the `score` sort key.
- [`Documents` models](../models.md) — pagination and the list-query lifecycle.
