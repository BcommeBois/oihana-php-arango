# AQL operations `db/operations/`

The [`src/oihana/arango/db/operations/`](../../../src/oihana/arango/db/operations/) folder provides the **22 operations** that match the *high-level operations* of the AQL language. Each function produces the corresponding AQL text fragment and can freely concatenate with the others to form a complete query.

For the pedagogical overview of composition, see [Building an AQL query step by step](aql-building-queries.md). This page is the **reference** for each operation.

## Categories

| Category | Operations |
|---|---|
| Iteration | `aqlFor`, `aqlSearch` |
| Restriction | `aqlFilter`, `aqlPrune` |
| Intermediate variable | `aqlLet` |
| Aggregation | `aqlCollect`, `aqlCollectReturn`, `aqlWindow` |
| Sorting | `aqlSort`, `aqlAsc`, `aqlDesc` |
| Pagination | `aqlLimit` |
| Return | `aqlReturn` |
| Modification | `aqlInsert`, `aqlUpdate`, `aqlReplace`, `aqlUpsert`, `aqlRepsert`, `aqlRemove` |
| Graph traversal | `aqlTraversal`, `aqlTraversalRange` |
| Configuration | `aqlOptions`, `aqlWith` |

## Iteration

### `aqlFor()`

```php
function aqlFor( array $init = [] ) : string
```

Builds the `FOR <var> IN <expression>` clause with optional `SEARCH` and `OPTIONS`. Consumes the keys `AQL::DOC_REF` (iteration variable name), `AQL::IN` (collection or sub-query), `AQL::SEARCH` (indexed filter via an ArangoSearch view), `AQL::OPTIONS` (hydrated into `ForOptions`).

```php
aqlFor( [ AQL::DOC_REF => 'doc' , AQL::IN => 'users' ] ) ;
// "FOR doc IN users"
```

Official docs: [`FOR`](https://docs.arangodb.com/stable/aql/high-level-operations/for/).

### `aqlSearch()`

```php
function aqlSearch( array $init = [] ) : string
```

Builds the `SEARCH <expression>` clause used inside a `FOR` that iterates over an ArangoSearch view. Filtering is resolved by the view's inverted index, faster than a classic `FILTER` on large volumes, and enables scoring (BM25/TFIDF) and *analyzers*. Called internally by `aqlFor()` when `AQL::SEARCH` is supplied, but can be used standalone.

`$init` keys:

| Key | Type | Description |
|---|---|---|
| `AQL::SEARCH` | `string\|array` | The search expression (required — without it everything else is ignored). |
| `AQL::ANALYZER` | `string` | Optional Analyzer name: the expression is wrapped in `ANALYZER(expr, "name")` via the [`analyzer()`](aql-functions-search.md) helper. |
| `AQL::SEARCH_OPTIONS` | `array\|SearchOptions\|object\|string` | Optional `SEARCH … OPTIONS { … }` object: `collections`, `conditionOptimization` (`ConditionOptimization::AUTO/NONE`), `countApproximate` (`CountApproximate::EXACT/COST`), `parallelism`. Arrays are hydrated into `SearchOptions` (unknown keys dropped, null properties omitted). |

> `AQL::SEARCH_OPTIONS` (the `SEARCH`-level options, for Views) is distinct from `AQL::OPTIONS` (the `FOR`-level options — `indexHint`, `useCache`, … — for collections). `aqlFor()` forwards its whole `$init`, so all three keys work through it directly.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\ConditionOptimization ;
use function oihana\arango\db\functions\search\phrase ;
use function oihana\arango\db\operations\aqlSearch ;

aqlSearch
([
    AQL::SEARCH         => phrase( 'doc.body' , 'quick fox' ) ,
    AQL::ANALYZER       => 'text_en' ,
    AQL::SEARCH_OPTIONS => [ 'conditionOptimization' => ConditionOptimization::NONE ] ,
]) ;
// SEARCH ANALYZER(PHRASE(doc.body,"quick fox"),"text_en") OPTIONS {"conditionOptimization":"none"}
```

```aql
FOR doc IN articlesView
  SEARCH ANALYZER( doc.body IN TOKENS( @q , 'text_en' ) , 'text_en' )
  SORT BM25( doc ) DESC
  RETURN doc
```

> `SEARCH` only applies to a *View*, not a collection. View and analyzer management is described in [`clients/arangosearch.md`](../clients/arangosearch.md); the search-expression helpers (`phrase`, `levenshteinMatch`, `bm25`, …) on the [ArangoSearch functions](aql-functions-search.md) page.

Official docs: [`SEARCH`](https://docs.arangodb.com/stable/aql/high-level-operations/search/).

## Restriction

### `aqlFilter()`

```php
function aqlFilter
(
    string|array|null $conditions      = null         ,
    string            $logicalOperator = Logic::AND   ,
    bool              $useParentheses  = false
) : ?string
```

Builds the `FILTER <expression>` clause. A single string is inserted as-is. An array of conditions is joined with the `$logicalOperator` separator (default `&&`, you can pass `||` or other). `$useParentheses` wraps the result in parentheses (useful to combine multiple `FILTER`s mixing `AND` / `OR`).

```php
aqlFilter( 'doc.active == true' ) ;
// "FILTER doc.active == true"

aqlFilter( [ 'doc.x > 5' , 'doc.y < 10' ] ) ;
// "FILTER doc.x > 5 && doc.y < 10"

aqlFilter( [ 'doc.x > 5' , 'doc.y < 10' ] , '||' ) ;
// "FILTER doc.x > 5 || doc.y < 10"
```

Official docs: [`FILTER`](https://docs.arangodb.com/stable/aql/high-level-operations/filter/).

### `aqlPrune()`

```php
function aqlPrune( ... ) : string
```

Variant of `FILTER` dedicated to graph traversals. Stops expansion of the current path as soon as the `PRUNE` condition is satisfied. Semantically very different from a `FILTER` which simply filters the results that come out. To be used inside an `aqlTraversal`.

Official docs: [`PRUNE`](https://docs.arangodb.com/stable/aql/graphs/traversals/#pruning).

## Intermediate variable

### `aqlLet()`

```php
function aqlLet( ... ) : string
```

Builds the `LET <name> = <expression>` clause. Defines a local variable scoped to the current `FOR`, evaluated once per loop iteration. Useful to factor a sub-expression reused multiple times, or to store a sub-query result.

Official docs: [`LET`](https://docs.arangodb.com/stable/aql/high-level-operations/let/).

## Aggregation

### `aqlCollect()`

```php
function aqlCollect( array $init = [] ) : string
```

Builds the `COLLECT` clause for grouping, aggregation and counting. Equivalent to SQL `GROUP BY` with additional support for AQL aggregate functions (`AGGREGATE total = SUM(doc.amount)`).

Unlike `WINDOW` (which keeps every row), `COLLECT` **reduces** the result: N rows → one row per distinct group.

```php
// Total sales per category
aqlCollect([ AQL::ASSIGN => [ 'category' => 'doc.category' ] , AQL::AGGREGATE => [ 'total' => 'SUM(doc.amount)' ] ]) ;
// COLLECT category = doc.category AGGREGATE total = SUM(doc.amount)

// Count per group
aqlCollect([ AQL::ASSIGN => [ 'status' => 'doc.status' ] , AQL::WITH_COUNT => 'count' ]) ;
// COLLECT status = doc.status WITH COUNT INTO count
```

> **Note:** `AQL::AGGREGATE` and `AQL::WITH_COUNT` are mutually exclusive in AQL. When both are supplied, `AGGREGATE` takes precedence and `WITH COUNT INTO` is dropped. To count alongside other aggregates, express the count as an aggregate (`['n' => 'LENGTH(1)']`).

The high-level wiring (`?groupBy=` / `Arango::GROUP`) is described in the [Grouping](../db/grouping.md) guide.

Official docs: [`COLLECT`](https://docs.arangodb.com/stable/aql/high-level-operations/collect/).

### `aqlCollectReturn()`

```php
function aqlCollectReturn( array $spec = [] , ?string $explicit = null ) : string
```

Builds the `RETURN` clause that follows a `COLLECT` produced by `aqlCollect()`. After a `COLLECT`, the iteration variable (`doc`) is out of scope: only the grouping variables, the aggregate variables and the optional `WITH COUNT` variable remain. This helper derives a valid projection from the **same** `$spec` given to `aqlCollect()`, so the two always stay in sync.

- A non-empty `$explicit` expression always wins (`RETURN <expr>`).
- Otherwise the projection is derived: grouping keys (`array_keys(AQL::ASSIGN)`) + aggregate keys (`array_keys(AQL::AGGREGATE)`) + the `AQL::WITH_COUNT` variable.
- A pure count (no grouping, no aggregate) returns the **scalar** count (`RETURN length`), not an object.
- Since `AQL::AGGREGATE` and `AQL::WITH_COUNT` are exclusive, the count variable is ignored when an aggregate is present.

```php
aqlCollectReturn( [ AQL::ASSIGN => [ 'status' => 'doc.status' ] ] ) ;
// RETURN { status }

aqlCollectReturn( [ AQL::ASSIGN => [ 'category' => 'doc.category' ] , AQL::WITH_COUNT => 'count' ] ) ;
// RETURN { category, count }

aqlCollectReturn( [ AQL::WITH_COUNT => 'length' ] ) ;
// RETURN length

aqlCollectReturn( [ AQL::ASSIGN => [ 'y' => 'DATE_YEAR(doc.created)' ] ] , '{ year: y }' ) ;
// RETURN { year: y }
```

> The high-level model wiring (the `Arango::GROUP` key / `Group` vocabulary, and the raw `Arango::COLLECT` key in a `list` query) is described in the [Grouping](../db/grouping.md) guide.

### `aqlWindow()`

```php
function aqlWindow( array $init = [] ) : string
```

#### What is `WINDOW` for?

`WINDOW` computes a **sliding aggregation**: for **each** result row, it aggregates the few *neighbouring* rows (preceding and/or following) and attaches the result to that row.

The key difference with `COLLECT`:

- `COLLECT` **reduces** the result: N rows → a few group rows. The row-level detail is lost.
- `WINDOW` **keeps every row**: N input rows → N output rows, each enriched with an aggregate computed over its window.

It is the tool for **running totals**, **rolling averages**, sliding rankings, "this row vs the last-7-days average" comparisons — anything that needs the detail **and** a contextual aggregate on the same row.

#### A concrete end-to-end example

A `sales` collection (one row per day):

| day | amount |
|---|---|
| 1 | 10 |
| 2 | 20 |
| 3 | 30 |

For each day we want the amount **and** the cumulative total from the start (running total):

```aql
FOR v IN sales
  SORT v.day
  WINDOW { preceding: 'unbounded', following: 0 }   // all previous rows + the current one
  AGGREGATE total = SUM(v.amount)
  RETURN { day: v.day, amount: v.amount, total }
```

Result — **one row per day kept**, with the total accumulating:

| day | amount | total |
|---|---|---|
| 1 | 10 | 10 |
| 2 | 20 | 30 |
| 3 | 30 | 60 |

> With `COLLECT` we would have gotten a **single** row (`60`), losing the per-day detail. That is the whole point of `WINDOW`: keep every row while adding a contextual aggregate.

Changing the window changes the computation: `{ preceding: 1, following: 1 }` would give the **rolling average** over the previous, current and next row (`AGGREGATE avg = AVG(v.amount)`).

On the PHP side, the same `WINDOW` is built with `aqlWindow()`:

```php
aqlWindow([ AQL::PRECEDING => 'unbounded' , AQL::FOLLOWING => 0 , AQL::AGGREGATE => [ 'total' => 'SUM(v.amount)' ] ]) ;
// WINDOW { preceding: 'unbounded', following: 0 } AGGREGATE total = SUM(v.amount)
```

#### Reference

Builds the `WINDOW` clause for **sliding-window** aggregation (running totals, rolling averages, and other statistics over neighbouring rows). Two forms, selected by the presence of `AQL::RANGE_VALUE`:

- **Row-based** (a fixed number of adjacent rows) — no `rangeValue`: `WINDOW { preceding: N, following: M } AGGREGATE …`
- **Range-based** (a value or duration range around `rangeValue`) — with `rangeValue`: `WINDOW <rangeValue> WITH { preceding: …, following: … } AGGREGATE …`

> The `WITH` keyword in the range-based form belongs to the `WINDOW` syntax and is **unrelated** to the collection-declaring `WITH` operation ([`aqlWith()`](#aqlwith)).

`$init` keys: `AQL::AGGREGATE` (required), `AQL::PRECEDING`, `AQL::FOLLOWING`, `AQL::RANGE_VALUE`. Numeric bounds are emitted as-is, string bounds are single-quoted (ISO 8601 durations such as `PT1H`). A `null` bound is omitted from the `{ … }` object.

```php
// Rolling average over 3 rows (previous, current, next)
aqlWindow([ AQL::PRECEDING => 1 , AQL::FOLLOWING => 1 , AQL::AGGREGATE => [ 'rollingAvg' => 'AVG(doc.val)' ] ]) ;
// WINDOW { preceding: 1, following: 1 } AGGREGATE rollingAvg = AVG(doc.val)

// Cumulative running total from the start of the result set
aqlWindow([ AQL::PRECEDING => 'unbounded' , AQL::FOLLOWING => 0 , AQL::AGGREGATE => [ 'runningTotal' => 'SUM(doc.val)' ] ]) ;
// WINDOW { preceding: 'unbounded', following: 0 } AGGREGATE runningTotal = SUM(doc.val)

// Range window by duration
aqlWindow([ AQL::RANGE_VALUE => 'doc.time' , AQL::PRECEDING => 'PT1H' , AQL::FOLLOWING => 0 , AQL::AGGREGATE => [ 'total' => 'SUM(doc.val)' ] ]) ;
// WINDOW doc.time WITH { preceding: 'PT1H', following: 0 } AGGREGATE total = SUM(doc.val)
```

> For an unbounded running total, ArangoDB expects the **string** `"unbounded"` (a bareword would be parsed as a collection name). The range-based form requires the input to be sorted by the range value: the AQL optimizer inserts a `SORT` in front of the `WINDOW` automatically.

Official docs: [`WINDOW`](https://docs.arangodb.com/stable/aql/high-level-operations/window/).

#### `aqlWindowBounds()`

```php
function aqlWindowBounds( int|float|string|null $preceding , int|float|string|null $following ) : string
```

Low-level helper that serializes the `{ preceding: …, following: … }` bounds object of a `WINDOW` clause. Used internally by `aqlWindow()`, but exposed separately (one file per helper) for reuse. Numeric bounds are emitted as-is, string bounds single-quoted (ISO 8601 durations, the `'unbounded'` keyword); a `null` bound is omitted.

```php
aqlWindowBounds( 1 , 1 ) ;            // { preceding: 1, following: 1 }
aqlWindowBounds( 'unbounded' , 0 ) ;  // { preceding: 'unbounded', following: 0 }
aqlWindowBounds( 0 , null ) ;         // { preceding: 0 }
```

## Sorting

### `aqlSort()`

```php
function aqlSort( string|array|null $expression ) : string
```

Builds the `SORT <expression>` clause. Accepts a raw AQL string (`'doc.created DESC, doc.name ASC'`) or an array of expressions to join by comma.

```php
aqlSort( 'doc.created DESC' )                ;  // "SORT doc.created DESC"
aqlSort( [ 'doc.created DESC' , 'doc.name' ] ) ;  // "SORT doc.created DESC, doc.name"
```

Official docs: [`SORT`](https://docs.arangodb.com/stable/aql/high-level-operations/sort/).

### `aqlAsc()` and `aqlDesc()`

```php
function aqlAsc ( string $key , ?string $prefix = null ) : string
function aqlDesc( string $key , ?string $prefix = null ) : string
```

Low-level helpers that respectively produce `"<prefix>.<key> ASC"` and `"<prefix>.<key> DESC"`. Avoid manual concatenation and the mix of typed constants / raw strings (see [Root helpers](../helpers.md) for the textual sort grammar on the HTTP side).

```php
aqlAsc ( 'name'   , 'doc' ) ;  // "doc.name ASC"
aqlDesc( 'created', 'doc' ) ;  // "doc.created DESC"
```

## Pagination

### `aqlLimit()`

```php
function aqlLimit( ... ) : string
```

Builds the `LIMIT <count>` clause (from the start) or `LIMIT <offset>, <count>` (with offset). Both forms are handled by the arguments.

```php
aqlLimit( 50      ) ;  // "LIMIT 50"
aqlLimit( 0  , 50 ) ;  // "LIMIT 0, 50"
```

Official docs: [`LIMIT`](https://docs.arangodb.com/stable/aql/high-level-operations/limit/).

## Return

### `aqlReturn()`

```php
function aqlReturn( mixed $expression , bool $distinct = false ) : string
```

Builds the `RETURN <expression>` clause. The second parameter adds the `DISTINCT` keyword to deduplicate results.

```php
aqlReturn( 'doc'           ) ;        // "RETURN doc"
aqlReturn( 'doc.email' , true ) ;     // "RETURN DISTINCT doc.email"
aqlReturn( '{ name: doc.name }' ) ;   // "RETURN { name: doc.name }"
```

Special AQL keywords usable here: `OLD` and `NEW` after a modification, `CURRENT` in some contexts.

Official docs: [`RETURN`](https://docs.arangodb.com/stable/aql/high-level-operations/return/).

## Modification

The six modification operations follow the same mechanic: an array of `AQL::*` keys (`KEY`, `DOCUMENT`, `WITH`, `COLLECTION`, `OPTIONS`) configures the instruction produced.

### `aqlInsert()`

```php
function aqlInsert( ... ) : string
```

Builds `INSERT { ... } INTO collection [OPTIONS { ... }]`. Inserts a new document. Raises a conflict if the key already exists (unless `OPTIONS { ignoreErrors: true }`).

Official docs: [`INSERT`](https://docs.arangodb.com/stable/aql/high-level-operations/insert/).

### `aqlUpdate()`

```php
function aqlUpdate( ... ) : string
```

Builds `UPDATE key WITH { ... } IN collection [OPTIONS { ... }]`. **Partial** update: merges the provided attributes with those of the existing document. Attributes absent from `WITH` are preserved.

Official docs: [`UPDATE`](https://docs.arangodb.com/stable/aql/high-level-operations/update/).

### `aqlReplace()`

```php
function aqlReplace( array $init = [] ) : string
```

Builds `REPLACE key WITH { ... } IN collection [OPTIONS { ... }]`. Replaces the document **integrally**: any attribute absent from `WITH` is lost. To use only when the full document is known.

Official docs: [`REPLACE`](https://docs.arangodb.com/stable/aql/high-level-operations/replace/).

### `aqlUpsert()`

```php
function aqlUpsert( array $init = [] ) : string
```

Builds `UPSERT { search } INSERT { ... } UPDATE { ... } IN collection [OPTIONS { ... }]`. Inserts if the search document does not exist, otherwise updates with the `UPDATE` clause (partial). This is the **idempotent** write par excellence: replaying the same query does not create a duplicate.

Each block (`search` / `insert` / `update`) is a **list of `[key, value]` pairs**:

```php
aqlUpsert
([
    'search' => [ [ 'foo' , 'bar' ] ] ,
    'insert' => [ [ 'foo' , 'bar' ] ] ,
    'update' => [ [ 'foo' , 'baz' ] ] ,
]) ;
// UPSERT {foo:'bar'} INSERT {foo:'bar'} UPDATE {foo:'baz'} IN @@collection RETURN NEW
```

The `return` key accepts `Clause::WITH_STATUS` to distinguish insert from update in the `RETURN`.

Official docs: [`UPSERT`](https://docs.arangodb.com/stable/aql/high-level-operations/upsert/).

### `aqlRepsert()`

```php
function aqlRepsert( array $init = [] ) : string
```

Variant of `UPSERT` where the `UPDATE` branch is replaced with a `REPLACE`. Inserts if absent, replaces integrally otherwise. Useful when the application always has the complete document version.

### `aqlRemove()`

```php
function aqlRemove( array $init = [] ) : string
```

Builds `REMOVE key IN collection [OPTIONS { ... }]`. Removes a document. `OPTIONS { ignoreRevs: false }` enables MVCC revision check.

Official docs: [`REMOVE`](https://docs.arangodb.com/stable/aql/high-level-operations/remove/).

## Graph traversal

### `aqlTraversal()`

```php
function aqlTraversal( array $init = [] , ?array &$binds = null ) : string
```

Builds the complete traversal clause `FOR v[, e[, p]] IN <range> <DIRECTION> <start> GRAPH '<name>'` (or, without a named graph, `… <start> <edgeCollection>`). Consumes the keys `AQL::VERTEX_REF`, `AQL::EDGE_REF`, `AQL::PATH_REF`, `AQL::MIN_DEPTH`, `AQL::MAX_DEPTH`, `AQL::DIRECTION` (`Traversal::OUTBOUND`, `INBOUND`, `ANY`), `AQL::START_VERTEX`, `AQL::GRAPH` or `AQL::EDGE_COLLECTION`. The `$binds` parameter is passed by reference to accumulate internal *bind variables*.

```php
// Named-graph traversal, depth 1..3
aqlTraversal
([
    AQL::VERTEX_REF   => 'v' ,
    AQL::MIN_DEPTH    => 1   ,
    AQL::MAX_DEPTH    => 3   ,
    AQL::DIRECTION    => Traversal::OUTBOUND ,
    AQL::START_VERTEX => '@startVertex'      ,
    AQL::GRAPH        => 'social'            ,
]) ;
// "FOR v IN 1..3 OUTBOUND @startVertex GRAPH 'social'"

// INBOUND traversal over an anonymous edge collection, with an edge variable
aqlTraversal
([
    AQL::VERTEX_REF      => 'v' ,
    AQL::EDGE_REF        => 'e' ,
    AQL::DIRECTION       => Traversal::INBOUND ,
    AQL::START_VERTEX    => 'comments/42' ,
    AQL::EDGE_COLLECTION => 'authored' ,
]) ;
// "FOR v, e IN INBOUND 'comments/42' authored"
```

> On `Edges` models, `getInboundVertices()` / `getOutboundVertices()` / `getAnyVertices()` wrap `aqlTraversal()` and add the `FILTER`, `SORT`, `LIMIT` and the `WITH` clause (cluster) automatically. See [`clients/graphs.md`](../clients/graphs.md).

Official docs: [Graph traversals](https://docs.arangodb.com/stable/aql/graphs/traversals/).

### `aqlTraversalRange()`

```php
function aqlTraversalRange( ... ) : string
```

Builds the `<min>..<max>` fragment (e.g. `1..1`, `1..5`, `..2`, `3..`). Used internally by `aqlTraversal()` for the traversal scope; exposed independently for cases where you need to compute the range separately.

## Vector search

### `aqlVectorSearch()` — Approximate nearest-neighbour search

```php
function aqlVectorSearch(
    string  $collection ,
    string  $attribute ,
    string  $vector ,
    int     $limit ,
    string  $metric  = 'cosine' , // 'cosine' or 'l2'
    ?int    $nProbe  = null ,
    string  $docRef  = 'doc' ,
    ?string $return  = null ,
) : string
```

Builds a complete approximate nearest-neighbour (ANN) query over a [`vector` index](../clients/indexes.md), in the canonical form `FOR … SORT APPROX_NEAR_…(…) [DESC|ASC] LIMIT … RETURN …`. It composes `aqlFor()`, the `approxNear*` numeric functions, `aqlSort()`, `aqlLimit()` and `aqlReturn()`.

The `$metric` selects **both** the function and the sort direction — the part that is easy to get wrong:

| `$metric` | Function | Sort | Nearest is |
|---|---|---|---|
| `'cosine'` (default) | `APPROX_NEAR_COSINE` | `DESC` | closer to `1` |
| `'l2'` | `APPROX_NEAR_L2` | `ASC` | closer to `0` |

It must match the metric of the `VectorIndex` covering `$attribute`, otherwise the optimiser cannot accelerate the query. An unsupported metric throws `InvalidArgumentException`. Vector indexes are an **experimental** ArangoDB feature (server started with `--experimental-vector-index`).

```php
use function oihana\arango\db\operations\aqlVectorSearch ;

// Top-10 cosine neighbours, query vector bound as @query:
aqlVectorSearch( collection: 'items', attribute: 'embedding', vector: '@query', limit: 10 ) ;
// "FOR doc IN items SORT APPROX_NEAR_COSINE(doc.embedding,@query) DESC LIMIT 10 RETURN doc"

// L2 metric, custom nProbe, iteration variable and projection:
aqlVectorSearch(
    collection: 'items', attribute: 'embedding', vector: '@query',
    limit: 5, metric: 'l2', nProbe: 20, docRef: 'd',
    return: '{ key: d._key, score: APPROX_NEAR_L2(d.embedding, @query) }' ,
) ;
// "FOR d IN items SORT APPROX_NEAR_L2(d.embedding,@query,{"nProbe":20}) ASC LIMIT 5
//   RETURN { key: d._key, score: APPROX_NEAR_L2(d.embedding, @query) }"
```

End-to-end (PHP client), nearest documents to an embedding:

```php
$aql  = aqlVectorSearch( collection: 'items', attribute: 'embedding', vector: '@query', limit: 10 ) ;
$rows = iterator_to_array( $db->query( $aql , [ 'query' => $embedding ] ) , false ) ;
```

The lower-level `approxNearCosine()` / `approxNearL2()` / `l1Distance()` / `l2Distance()` helpers are documented in [Numeric functions › Vectors](aql-functions-numerics.md#vectors).

## Configuration

### `aqlOptions()`

```php
function aqlOptions( ... ) : string
```

Builds the `OPTIONS { ... }` clause that annotates an AQL operation (FOR, INSERT, UPDATE, ...) with specific options: `indexHint`, `forceIndexHint`, `useCache`, `ignoreErrors`, `waitForSync`, etc. The nature of the options depends on the host operation; the function delegates to a typed `Options` class for validation (see [AQL options reference](../options.md)).

### `aqlWith()`

```php
function aqlWith( string ...$collections ) : string
```

Builds the `WITH coll1, coll2, ...` clause that explicitly declares the collections referenced by the query. Useful in cluster mode when the planner cannot infer dependencies (e.g. through dynamic sub-queries).

```php
aqlWith( 'users' , 'orders' , 'products' ) ;
// "WITH users, orders, products"
```

> **Automatic emission on anonymous traversals.** The edge-traversal methods (`getOutboundVertices()`, `getInboundVertices()`, `getAnyVertices()`, `countVertices()` and their variants) now prefix the query with a `WITH` clause whenever they traverse an **anonymous graph** (an edge collection, with no named graph). The reachable vertex collections are declared by direction: `OUTBOUND` → the `_to` collection, `INBOUND` → the `_from` collection, `ANY` → both (de-duplicated). This is required to avoid deadlocks in a cluster, and is a no-op on a single server. Nothing is emitted for a **named-graph** traversal (its collections are already known). The declared collections can be overridden through the method's `AQL::WITH` option key.

Official docs: [`WITH`](https://docs.arangodb.com/stable/aql/high-level-operations/with/).

## See also

- [Building an AQL query step by step](aql-building-queries.md) — pedagogical assembly narrative.
- [Operators `db/operators/`](aql-operators.md) — catalog of the 42 operators consumed by `aqlFilter`.
- [AQL helpers `db/helpers/`](../db/helpers.md) — value encoding, CUD sub-expressions, *field builders*.
- [Bind variables `db/binds/`](../db/binds.md) — safe injection.
- [Official AQL documentation — high-level operations](https://docs.arangodb.com/stable/aql/high-level-operations/).
