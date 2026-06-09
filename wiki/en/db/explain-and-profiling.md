# Explaining and profiling queries

Two questions come up constantly when tuning an ArangoDB query:

1. **"Will this query use my index, or is it a full collection scan?"** → *explain* it.
2. **"Why is this query slow — where does the time actually go?"** → *profile* it.

The `db/` layer answers both with **typed result objects**, so you read `result->usesIndex()` and `result->indexesUsed()` instead of digging through a deep, version-dependent JSON plan tree.

> `explain` is a **core** ArangoDB API (no special server flag). It analyses the query **without executing it**.

## At a glance

| You have… | Call | You get back |
|---|---|---|
| A raw AQL string | `ArangoDB::explain($query, $binds)` | [`ExplainResult`](#the-explainresult-object) |
| A `Documents` model + list filters | `Documents::explainList($init)` | [`ExplainResult`](#explaining-a-model-list-query) for the exact `list()` query |

## Explaining a raw query

`ArangoDB::explain()` asks the optimizer for the plan it *would* run and wraps it in an [`ExplainResult`](#the-explainresult-object):

```php
use oihana\arango\db\ArangoDB ;

$db = new ArangoDB( $config ) ; // the [arango] config section

$plan = $db->explain(
    'FOR u IN users FILTER u.age > @min SORT u.name LIMIT 5 RETURN u' ,
    [ 'min' => 30 ] ,
) ;

$plan->usesIndex() ;        // true  → the FILTER is index-accelerated
$plan->rules() ;            // ['…','use-indexes','remove-filter-covered-by-index', …]
$plan->collections() ;     // ['users']
$plan->estimatedCost() ;   // 122.76  (optimizer cost estimate)
```

`explain()` also accepts an `AqlQuery` (which carries its own binds) and an `$options` array forwarded to the server (`allPlans`, `optimizer.rules`, …):

```php
use oihana\arango\clients\aql\AqlQuery ;

$db->explain( new AqlQuery( 'FOR u IN users RETURN u' ) ) ;
$db->explain( $query , $binds , [ 'optimizer' => [ 'rules' => [ '-use-indexes' ] ] ] ) ; // force a scan to compare
```

## The `ExplainResult` object

[`oihana\arango\db\results\ExplainResult`](../../../src/oihana/arango/db/results/ExplainResult.php) is a read-only view over the `/_api/explain` response:

| Method | Returns | Meaning |
|---|---|---|
| `usesIndex()` | `bool` | `true` if the query hits at least one index (not a full scan). |
| `indexesUsed()` | `IndexUse[]` | The indexes the query actually uses — see below. |
| `rules()` | `string[]` | The optimizer rules that fired (`use-indexes`, `move-filters-up`, …). |
| `collections()` | `string[]` | The collections the query reads/writes. |
| `nodeTypes()` | `string[]` | The execution node types, in order (`IndexNode`, `SortNode`, …). |
| `estimatedCost()` | `float` | The optimizer's estimated cost. |
| `estimatedNrItems()` | `int` | The estimated number of result rows. |
| `isModificationQuery()` | `bool` | Whether the query writes data. |
| `isCacheable()` | `bool` | Whether the result could be served from the query cache. |
| `warnings()` | `array` | Optimizer warnings. |
| `plan()` / `raw()` | `array` | The raw plan / full response, for anything not surfaced above. |

### "Which indexes does my query use?"

`indexesUsed()` returns one [`IndexUse`](../../../src/oihana/arango/db/results/IndexUse.php) per index the optimizer picked, gathered from every `IndexNode` of the plan:

```php
foreach ( $plan->indexesUsed() as $index )
{
    echo $index->name ;        // "idx_age"
    echo $index->type ;        // "persistent" | "primary" | "geo" | "vector" | …
    echo $index->collection ;  // "users"
    echo implode( ',' , $index->fields ) ; // "age"
    $index->unique ;           // false
    $index->selectivityEstimate ; // 1.0  (0…1, when available)
}
```

A common assertion in a test or a health check:

```php
// Fail fast if a hot query silently degrades to a full collection scan.
if ( ! $db->explain( $query , $binds )->usesIndex() )
{
    throw new RuntimeException( 'Query is not index-accelerated — add or fix an index.' ) ;
}
```

## Explaining a model list query

When you build a list with the `Documents` model (filters, facets, search, sort, pagination), you rarely write the AQL by hand — so you also can't easily eyeball whether it uses your indexes. `Documents::explainList()` explains **the exact query `list()` would run** for the same input:

```php
$init =
[
    'active' => true ,
    'filter' => [ 'age' => [ '$gte' => 30 ] ] ,
    'sort'   => [ 'name' => 'ASC' ] ,
    'limit'  => 20 ,
] ;

$plan = $users->explainList( $init ) ; // same $init you would pass to $users->list( $init )

$plan->usesIndex() ;       // does my filter/sort actually hit an index?
$plan->indexesUsed() ;     // which ones
$plan->rules() ;           // what the optimizer did
```

`explainList()` builds the query and explains it — it **does not execute** it and returns no documents. Use it in development, tests, or an admin/debug endpoint to validate that your declared indexes cover your real query shapes.

The low-level primitive is available on any model through `explain( string $query, array $binds = [], array $options = [] ): ExplainResult` (from `ArangoTrait`), and on the façade as `ArangoDB::explain()`.

## End-to-end example

```php
use oihana\arango\db\ArangoDB ;
use oihana\arango\clients\collection\indexes\PersistentIndex ;

$db = new ArangoDB( $config ) ;

// 1. Make sure the index exists.
$db->createIndex( 'users' , new PersistentIndex( fields : [ 'age' ] ) ) ;

// 2. Explain the query you care about.
$plan = $db->explain(
    'FOR u IN users FILTER u.age > @min SORT u.name LIMIT 5 RETURN u' ,
    [ 'min' => 30 ] ,
) ;

// 3. Assert it is index-accelerated and see how.
assert( $plan->usesIndex() ) ;
echo $plan->indexesUsed()[ 0 ]->fields[ 0 ] ;   // "age"
echo implode( ', ' , $plan->rules() ) ;          // "… use-indexes, remove-filter-covered-by-index …"
```

## Wiring / DI

`ArangoDB::explain()` needs nothing beyond a configured façade — the same `ArangoDB` instance your models already receive (see [Quickstart `ArangoDB`](quickstart.md) for construction and DI). `Documents::explainList()` is available on every `Documents` model out of the box. Nothing to register.

## See also

- [Quickstart `ArangoDB`](quickstart.md) — building and configuring the façade.
- [Indexes](../clients/indexes.md) — the index types whose use `indexesUsed()` reports.
- [Building an AQL query step by step](../aql/aql-building-queries.md).
- [Official AQL documentation — Explaining queries](https://docs.arangodb.com/stable/aql/execution-and-performance/explaining-queries/).
