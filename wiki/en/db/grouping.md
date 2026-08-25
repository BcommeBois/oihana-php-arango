# HTTP grouping `?groupBy=` / `?group=`

Grouping turns a `list` query into an **aggregation**: instead of returning the documents, ArangoDB **groups** them by one or more keys and returns one row per group (count, sum, average…). It is the SQL `GROUP BY` equivalent, built on the AQL [`COLLECT`](../aql/aql-operations.md#aqlcollect) clause.

It is the right lever for **dashboards** and **counters**: "how many articles per category", "revenue per year", "average rating per author".

## Grouping vs facets vs filters

| Lever | Effect | Returns |
|---|---|---|
| `?filter=` / `?search=` | restricts the set | the **documents** |
| `?facets=` | restricts via relations/aggregates | the **documents** |
| `?groupBy=` / `?group=` | **groups and aggregates** | one **row per group** |

> ⚠️ Under `COLLECT`, the `doc` variable is out of scope: projection (`fields`, `skin`) and document sorting (`?sort=`) no longer apply. Group sorting is done via `Group::SORT` (see below).

## URL syntax

Two combinable parameters:

### `?groupBy=` — the shortcut

CSV of fields; **implies a per-group count** (the common faceted case):

```
GET /sales?groupBy=category
// COLLECT category = doc.category WITH COUNT INTO count
// → [ {"category":"A","count":3}, {"category":"B","count":2} ]
```

### `?group=` — the full JSON spec

A JSON object (URL-encoded) with short keys:

| Key | Role | Example |
|---|---|---|
| `by` | grouping field(s) | `"category"` · `"category,status"` · `{"year":"created"}` |
| `agg` | aggregates | `{"total":"sum:amount","avg":"avg:amount"}` |
| `count` | per-group count | `true` or `"n"` (variable name) |
| `sort` | sort on group/aggregate variables | `"-count"` · `"category,-total"` |
| `alt` | grouping-key transforms | `{"year":"dateYear"}` |

> ⚠ A transformation parameter supplied by the URL is **bound**, not written into the query — so it can no longer name another field. See [A parameter supplied by a request is a value](filter.md#-a-parameter-supplied-by-a-request-is-a-value).

> ⚠ An `alt` naming a function the catalog does not carry is **refused** (`400`), not silently applied to the raw key. See [A name the catalog does not carry is refused](filter.md#a-name-the-catalog-does-not-carry-is-refused).


```
GET /sales?group={"by":{"year":"created"},"alt":{"year":"dateYear"},"agg":{"total":"sum:amount"},"sort":"-total"}
// COLLECT year = DATE_YEAR(doc.created) AGGREGATE total = SUM(doc.amount) SORT total DESC RETURN {year, total}
```

The available aggregate functions (`agg`) are `sum`, `avg`, `min`, `max` (the `FacetAggregator` catalogue, shared with facets). The `"func:field"` form is equivalent to `["func","field"]`.

The **fields** a client may aggregate are restricted separately, through [`Arango::AGGREGATABLE`](#restricting-aggregatable-fields).

## Model side

Without HTTP, pass the same spec via the `Arango::GROUP` key, using the [`Group`](../../../src/oihana/arango/models/enums/Group.php) vocabulary:

```php
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Group;

$model->list
([
    Arango::GROUP =>
    [
        Group::BY    => 'category' ,
        Group::AGG   => [ 'total' => 'sum:amount' ] ,
        Group::COUNT => 'n' ,
        Group::SORT  => '-total' ,
    ] ,
]) ;
// COLLECT category = doc.category AGGREGATE total = SUM(doc.amount), n = LENGTH(1) SORT total DESC RETURN {category, total, n}
```

## The three uses

### 1. Distinct values

```php
$model->list([ Arango::GROUP => [ Group::BY => 'status' ] ]) ;
// COLLECT status = doc.status RETURN { status }
```

### 2. Per-group count (facet counts)

```php
$model->list([ Arango::GROUP => [ Group::BY => 'category' , Group::COUNT => true , Group::SORT => '-count' ] ]) ;
// COLLECT category = doc.category WITH COUNT INTO count SORT count DESC RETURN { category, count }
```

### 3. Aggregation / reporting

```php
$model->list
([
    Arango::GROUP =>
    [
        Group::BY  => [ 'year' => 'created' ] ,
        Group::ALT => [ 'year' => 'dateYear' ] ,
        Group::AGG => [ 'total' => 'sum:amount' , 'avg' => 'avg:amount' ] ,
    ] ,
]) ;
// COLLECT year = DATE_YEAR(doc.created) AGGREGATE total = SUM(doc.amount), avg = AVERAGE(doc.amount) RETURN { year, total, avg }
```

> **Count + aggregates.** `AGGREGATE` and `WITH COUNT INTO` are mutually exclusive in AQL. When a `count` accompanies aggregates, it is emitted as `n = LENGTH(1)` (not `WITH COUNT`).

## What a grouped query returns

A `COLLECT` replaces the documents by rows the query invented: the dimensions, the aggregates, the count. **A grouped row is therefore not a document**, and neither the model's schema nor its `alters` apply to it — their names are the collection's, the row's are the ones the client asked for.

`list()` and `stream()` read those rows **as they are**. They come back as plain objects carrying exactly the variables the `COLLECT` emitted:

```php
$model->list([ Arango::GROUP => [ Group::BY => 'year' , Group::AGG => [ 'total' => 'sum:speed.value' ] ] ]) ;
// [ { "year": 2023, "total": 150.5 } , { "year": 2024, "total": 20.5 } ]
```

A list **without** grouping does not change by one character: its documents go through the schema and the `alters` as before.

### ⚠ Why this is not a detail

For as long as those rows went through the schema, they lost everything the query had invented there. A `Thing`'s constructor copies only the keys matching a **declared public property** — and a collection class declares neither `year` nor `total`. Measured, with a schema declaring neither:

| what the server computed | what reached the reader |
|---|---|
| `{ "year": 2023, "total": 150.5 }` | `{ "@type": "Thing" }` |

Not "the aggregate is missing": **everything is missing**, the dimension included. And the answer came back in `200`, well-formed, carrying an `@type` the class held while the row was no longer a document.

🚨 **The dangerous case is the one where the name *does* match.** Those properties are typed, and PHP coerces:

| requested aggregate | computed | property hit | what reached the reader |
|---|---|---|---|
| `"name": "sum:speed.value"` | `150.5` | `name` (`string\|int\|null`) | **`150`** |
| `"active": "sum:amount"` | `10` | `active` (`?bool`) | **`true`** |

A sum of `10` answered as `true`. A wrong value does not show, where a missing key does.

### The switch follows the emitted `COLLECT`, not the requested `?group=`

A request whose **every** dimension is dropped — undeclared in `Arango::GROUPABLE`, or closed by the permission gate — and which carries no aggregate emits no `COLLECT` at all: the query still returns documents, and they are still hydrated.

```php
// 'unknown' is not declared groupable and there is no aggregate:
$model->list([ Arango::GROUP => [ Group::BY => 'unknown' ] ]) ;
// no COLLECT → documents, hydrated as usual
```

The [raw `Arango::COLLECT` spec](#raw-arangocollect-spec) is the other door into the same clause: it switches the read just the same.

## Dotted fields and naming

A nested field becomes an underscore variable (a valid AQL identifier):

```php
$model->list([ Arango::GROUP => [ Group::BY => 'address.city' ] ]) ;
// COLLECT address_city = doc.address.city RETURN { address_city }
```

To name the variable explicitly, use the `{ varName: field }` form: `Group::BY => [ 'city' => 'address.city' ]`.

## Group sorting

Document sorting (`?sort=`) does not work under `COLLECT`. Sort on the **group/aggregate variables** via `Group::SORT` (or `sort` in JSON), a CSV with `-` for descending:

```
?group={"by":"category","count":true,"sort":"-count"}   // SORT count DESC
```

## Overriding `RETURN`

The projection is derived automatically (group keys + aggregates + count). For a custom `RETURN`, pass `Arango::RETURN`:

```php
$model->list
([
    Arango::GROUP  => [ Group::BY => [ 'y' => 'created' ] , Group::ALT => [ 'y' => 'dateYear' ] , Group::AGG => [ 't' => 'sum:amount' ] ] ,
    Arango::RETURN => '{ year: y, revenue: t }' ,
]) ;
```

## Raw `Arango::COLLECT` spec

For full control (free AQL expressions, `INTO`, `KEEP`, projection), bypass the `Group` vocabulary with the raw spec consumed by [`aqlCollect()`](../aql/aql-operations.md#aqlcollect):

```php
$model->list
([
    Arango::COLLECT =>
    [
        AQL::ASSIGN => [ 'author' => 'doc.authorId' ] ,
        AQL::INTO   => 'docs' ,
    ] ,
    Arango::RETURN => '{ author, count: LENGTH(docs), articles: docs }' ,
]) ;
```

> The raw spec is a **trusted AQL expression**: it is not validated. Never inject unsanitized user input into it.

## Security and AQL injection

A grouping field becomes `doc.<field>` *literally*. Every field of the `Group` layer (`by` and `agg`) is therefore validated by [`assertAttributeName()`](helpers.md): a non-conforming value (`category) RETURN doc //`) throws a `ValidationException`.

### Restricting groupable fields

Optionally define a `groupable` whitelist/mapping on the model (like `sortable`): `url-key → real field`. Only whitelisted keys are groupable, and the public key is decoupled from the internal field. It is declared in the model `$init`, like every other option:

```php
$articles = new Documents( $container ,
[
    Arango::COLLECTION => 'articles' ,
    Arango::GROUPABLE  => [ 'cat' => 'category' , 'year' => 'created' ] ,
]) ;
// ?groupBy=cat       → COLLECT cat = doc.category ...
// ?groupBy=secret    → ignored (not whitelisted)
```

When `groupable` is `null` (default), grouping is **fail-closed**: nothing is groupable (see *Permission* below).

### Restricting aggregatable fields

**The situation.** The two halves of `?group=` did not answer to the same law: `by` is **closed** (nothing is groupable without a whitelist), `agg` was **open** — every projected path could be aggregated. But an aggregate over a sub-field no document carries raises **neither an error nor a warning**: in AQL, `SUM` over `null`s is `0`.

```
?group={"by":"sensor","agg":{"total":"sum:pressure.value"}}
→ [ {"sensor":"A","total":0}, {"sensor":"B","total":0} ]
```

The answer is well-formed, in `200`, and **wrong**: nothing tells "the sum is zero" apart from "this field has no total". `Arango::AGGREGATABLE` closes that door.

⚠ It keys on the **field token**, not on the output name — that one (`total`) is chosen freely by the client, so whitelisting it would mean nothing:

```php
use oihana\arango\models\enums\AggregatablePolicy;

$measures = new Documents( $container ,
[
    Arango::COLLECTION          => 'measures' ,
    Arango::AGGREGATABLE        => [ [ 'speed' => 'speed.value' ] , 'weight' ] ,
    Arango::AGGREGATABLE_POLICY => AggregatablePolicy::STRICT ,
]) ;
// ?group={"agg":{"t":"sum:speed"}}           → AGGREGATE t = SUM(doc.speed.value)
// ?group={"agg":{"t":"sum:pressure.value"}}  → per the policy (below)
```

The three [`AQL::SORTABLE`](sort.md) notations are accepted and may be mixed: the indexed shorthand (`'weight'`, token equals field), the indexed alias (`[ 'speed' => 'speed.value' ]`) and the historical associative form.

#### The policy

| `Arango::AGGREGATABLE_POLICY` | An undeclared aggregate | For whom |
|---|---|---|
| `AggregatablePolicy::OPEN` | **passes** on its raw path (a declared alias still resolves) | a migration ramp: the aliases first, the closing later |
| `AggregatablePolicy::DROP` *(default once declared)* | **dropped**, like an undeclared dimension | a public API, where a missing column is seen at once |
| `AggregatablePolicy::STRICT` | `ValidationException` **naming the refused token** | an internal API, where a plain refusal beats a plausible zero |

A dropped aggregate takes nothing with it: the dimension, the count and the group sort stay intact (the sort never names a variable the `COLLECT` did not emit).

> **Backward compatibility.** Without a declared `AGGREGATABLE`, every projected path stays aggregatable and the emitted AQL is identical **character for character**. **Declaring the whitelist is what closes the door**; with no policy named, it closes on `DROP`. An unknown policy code closes too, so a typo never reopens anything.

> 🚨 **`STRICT` stops at the whitelist, never at the permission.** A whitelisted field refused by `Field::REQUIRES` is dropped **in silence**, even under `STRICT`: an error naming a protected field would tell the client that field exists — the very oracle the permission gate is there to close.


#### A computed aggregate: `AggregateExpression`

**The situation.** An aggregate compiles `FUNCTION(doc.path)` — one function, one path, nothing in
between. It can therefore read only **one place** in the document. Anything needing a composed read
is out of reach: summing three of the twelve readings stored in `pressure.values`, or summing the
difference between two arrays — a derived measure the source does not store.

An entry of `Arango::AGGREGATABLE` may therefore hold a **declared expression** instead of a path.
The library learns that an aggregate can be computed; **what** it computes stays the business of the
model that declares it.

```php
use oihana\arango\models\interfaces\AggregateExpression;

final class PressureWindow implements AggregateExpression
{
    public function paths() : array
    {
        return [ 'pressure.values' ] ; // what the expression READS
    }

    public function compile( string $docRef , array $init ) : ?string
    {
        $binder = $init[ Arango::BINDER ] ?? null ;
        $offset = is_callable( $binder ) ? $binder( 3 ) : 3 ; // a value is BOUND

        return sprintf( 'SUM(SLICE(%s.pressure.values,%s,3))' , $docRef , $offset ) ;
    }
}

Arango::AGGREGATABLE => [ 'pressureWindow' => new PressureWindow() ] ,
```

```
?group={"by":"sensor","agg":{"total":"sum:pressureWindow"}}
→ COLLECT sensor = doc.sensor AGGREGATE total = SUM(SUM(SLICE(doc.pressure.values,@q_0,3)))
```

🔑 **The expression is per document, the aggregation stays with the engine.** `compile()` returns a
scalar, and the engine wraps it in the requested function — exactly as it wraps a path. `sum`,
`avg`, `min` and `max` keep the meaning they had.

⚠ An expression is declared in the **associative** form (`'key' => new Expression()`). The two
indexed notations only accept a string or an array.

##### The two guards, and why `paths()` exists

**1. The name guard no longer applies.** `assertAttributeName()` protects a path against injection;
an expression is not an attribute name, by construction. What replaces it is not trust, it is
**origin**: an expression is **always** a declaration of your own code, never a value from a
request. The caller only supplies a **public key already on the whitelist** — the whitelist stays
the only door — and everything coming from the wire enters through `Arango::BINDER`, never through
concatenation. It is the distinction
[`AltChain`](filter.md#-a-parameter-supplied-by-a-request-is-a-value) already draws between a signed
chain and a request one.

**2. 🚨 The permission gate must interrogate *every* path read — that is what `paths()` is for.** A
path-based aggregate has exactly one path to check; an expression reads several, which is the whole
point. Checking none of them, or only the first, would make a derived expression the **way around
`Field::REQUIRES`**: a field closed to the projection would come back out as a sum, in silence,
without a single essay turning red. The engine therefore hands them **all** to the gate, and **one
refusal withdraws the whole aggregate**, without a word — naming the protected field would tell the
client it exists.

⚠ **An empty `paths()` withdraws the aggregate.** An expression declaring no path declares that it
reads nothing. Read as "nothing to gate", it would be exactly the hole above; read as a refusal, a
mis-declaration costs the aggregate and **shows**. Declare what you read.

##### `compile()` must be pure

`compile()` runs **more than once per request**: the `COLLECT` spec is resolved once to decide
whether the query groups at all (see [the switch](#the-switch-follows-the-emitted-collect-not-the-requested-group)),
then again to build it — and the binds of that first pass are thrown away. An implementation
counting its calls, incrementing a counter or caching its first answer would emit a query that does
not say what it means.

##### Withdrawing

`compile()` may return `null`: the aggregate is withdrawn and **nothing else** — the dimension, the
count and the sort survive. It is the rule already in place for a path that is not aggregatable,
inherited rather than reinvented. The count then reverts from `LENGTH(1)` to `WITH COUNT INTO`,
which is the same count by another clause.

> **Backward compatibility.** A string entry compiles today's AQL **byte for byte**, pinned by an
> essay. The `OPEN`/`DROP`/`STRICT` policy does not move: it answers for the whitelist, and an
> expression **is** on it — a declared key resolves whatever the policy code says.

## Grouping through a relation

**The situation.** "How many articles per author" — where the author is not a
field of the article, but a document at the end of an edge. Declare the relation
as a dimension, and the group label is read from the linked document:

```php
AQL::FIELDS =>
[
    'author' => [ Field::FILTER => Filter::EDGE ] ,   // ⚠ singular relation
] ,

AQL::EDGES =>
[
    'author' => [ AQL::MODEL => $articlesAuthors , AQL::DIRECTION => Traversal::OUTBOUND ] ,
] ,

AQL::GROUPABLE =>
[
    'title' => 'title' ,
    'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name' ] ,
] ,
AQL::AGGREGATABLE => [ 'amount' => 'amount' ] ,
```

```
?group={"by":"author","agg":{"total":"sum:amount"},"count":true}
```
```aql
FOR doc IN @@articles
  COLLECT author = FIRST( FOR author_v IN OUTBOUND doc articles_authors OPTIONS { … } RETURN author_v.name )
  AGGREGATE total = SUM( doc.amount ), count = LENGTH(1)
  RETURN { author, total, count }
```

**Why the dimension carries its own traversal**, where a
[relational sort](sort.md#sorting-through-a-relation) merely names one: a grouped
query never projects. `doc` is consumed by the `COLLECT`, so `returnFields()` is
not called and no relation is projected — there is no `LET` to reach for. The
dimension is therefore a sub-query written inline in the `COLLECT`.

> **This is the composition the [facet counts](facets.md#counting-linked-documents-facetedge--facetjoin)
> cannot give you.** They already answer "how many per linked value"; what they
> cannot do is sit next to a `SUM` in the same pass.

- **`AQL::EDGE` names the field**, whose traversal is declared in `AQL::EDGES` —
  the same declaration the list projects, read through the same doors (depth
  range, `AQL::WHERE` / `AQL::PRUNE`, traversal options).
- **`Field::PATH` names the field of the related document**, and may be nested.
- **Permission** follows the relation: an explicit `Field::REQUIRES` on the
  dimension wins, otherwise the relation's subject is inherited. Grouping by a
  hidden field would return its distinct values in clear.

### Only a singular relation, and the reason is arithmetic

A **plural** relation is refused, because neither way of grouping on one is
sound. Measured over three articles worth 10, 20 and 30 — one of them linked to
two authors:

| Approach | Result |
|---|---|
| Keep the sub-query as an **array** | buckets `["Alice"]`, `["Alice","Zoe"]`, `["Zoe"]` — three buckets for two authors, grouping by the *combination* |
| **Unwind** the relation before the `COLLECT` | `Alice: 30`, `Zoe: 40` — a **sum of 70** where the truth is **60**, the two-author article counted twice |

The second is the dangerous one: it silently inflates **every other aggregate of
the same `COLLECT`**, not just the count. So a plural relation is refused rather
than guessed at.

> ⚠ The guard reads the **declaration**, not the data. A relation declared
> singular whose data holds several vertices is resolved by `FIRST()`, which
> picks one arbitrarily — the same contract a singular `Filter::EDGE` projection
> already has.

### What is refused, and how loudly

| Situation | Reaction |
|---|---|
| The relation is not declared in `AQL::EDGES` | **refused** (`500`) — no traversal to compile |
| It is plural, or not a relation at all | **refused** (`500`) — see above |
| No `Field::PATH` on the dimension | **refused** (`500`) — nothing labels the group |
| `?group=` names an unknown key | **dropped**, silently — unchanged contract |
| The permission refuses the dimension | **dropped**, silently — no grouping oracle |

## Permission (`REQUIRES`)

`?groupBy=` is **fail-closed**: without a declared `AQL::GROUPABLE`, **nothing is groupable** (like sorting). And a whitelisted dimension on a field **hidden from reading** (`Field::REQUIRES`) is still an **oracle**: `COLLECT` reveals its distinct values and their counts; an aggregate (`MAX/MIN/AVG/SUM`) leaks a **bound**.

The permission **inherits** from the homonymous field in `$fields`; when refused, the **dimension or aggregate is dropped** (removes an output, loosens nothing).

```php
public array  $fields    = [ 'category' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
public ?array $groupable = [ 'category' => 'category' , 'salary' => 'salary' ] ;
```

| User **with** `hr:read` | User **without** `hr:read` |
|---|---|
| `?group[group_by]=salary` → `COLLECT salary = doc.salary` | dimension **dropped** |
| `?group[group_agg][m]=max:salary` → `MAX(doc.salary)` | aggregate **dropped** |

> **Migration (BC).** `groupable = null` no longer means "everything groupable" but **nothing**: declare `AQL::GROUPABLE` with the keys a client may group on. **Fail-open**: with no `REQUIRES` / authorizer, a whitelisted field groups normally. **Depth**: a deep path (`address.city`) is gated at the **exact sub-field**, not only the root. See [Field projection](../projection.md) and [Sorting](sort.md#sort-permission).

## See also

- [Helpers: `aqlCollect()` / `aqlCollectReturn()`](../aql/aql-operations.md#aggregation) — the low-level AQL building blocks.
- [HTTP facets `?facets=`](facets.md) — filter via relations/aggregates (returns the documents).
- [Search & filtering](search-and-filtering.md) — overview of the levers.
