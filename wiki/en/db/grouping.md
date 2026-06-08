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

```
GET /sales?group={"by":{"year":"created"},"alt":{"year":"dateYear"},"agg":{"total":"sum:amount"},"sort":"-total"}
// COLLECT year = DATE_YEAR(doc.created) AGGREGATE total = SUM(doc.amount) SORT total DESC RETURN {year, total}
```

The available aggregate functions (`agg`) are `sum`, `avg`, `min`, `max` (the `FacetAggregator` catalogue, shared with facets). The `"func:field"` form is equivalent to `["func","field"]`.

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

Optionally define a `$this->groupable` whitelist/mapping on the model (like `$this->sortable`): `url-key → real field`. Only whitelisted keys are groupable, and the public key is decoupled from the internal field:

```php
$model->groupable = [ 'cat' => 'category' , 'year' => 'created' ] ;
// ?groupBy=cat       → COLLECT cat = doc.category ...
// ?groupBy=secret    → ignored (not whitelisted)
```

When `$this->groupable` is `null` (default), grouping is open but still protected against injection by `assertAttributeName()`.

## See also

- [Helpers: `aqlCollect()` / `aqlCollectReturn()`](../aql/aql-operations.md#aggregation) — the low-level AQL building blocks.
- [HTTP facets `?facets=`](facets.md) — filter via relations/aggregates (returns the documents).
- [Search & filtering](search-and-filtering.md) — overview of the levers.
