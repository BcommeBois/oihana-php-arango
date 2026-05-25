# AQL operations `db/operations/`

The [`api/src/oihana/arango/db/operations/`](../../../../api/src/oihana/arango/db/operations/) folder provides the **21 operations** that match the *high-level operations* of the AQL language. Each function produces the corresponding AQL text fragment and can freely concatenate with the others to form a complete query.

For the pedagogical overview of composition, see [Building an AQL query step by step](aql-building-queries.md). This page is the **reference** for each operation.

## Categories

| Category | Operations |
|---|---|
| Iteration | `aqlFor`, `aqlSearch` |
| Restriction | `aqlFilter`, `aqlPrune` |
| Intermediate variable | `aqlLet` |
| Aggregation | `aqlCollect` |
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

Builds the `SEARCH <expression>` clause used inside a `FOR` that iterates over an ArangoSearch view. Filtering is resolved by the view's inverted index, faster than a classic `FILTER` on large volumes. Called internally by `aqlFor()` when `AQL::SEARCH` is supplied, but can be used standalone.

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

Official docs: [`COLLECT`](https://docs.arangodb.com/stable/aql/high-level-operations/collect/).

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

Builds `UPSERT { search } INSERT { ... } UPDATE { ... } IN collection [OPTIONS { ... }]`. Inserts if the search document does not exist, otherwise updates with the `UPDATE` clause (partial).

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

Builds the complete traversal clause `FOR v[, e[, p]] IN <range> <DIRECTION> <start> GRAPH '<name>'`. Consumes the keys `AQL::VERTEX`, `AQL::EDGE`, `AQL::PATH`, `AQL::MIN`, `AQL::MAX`, `AQL::DIRECTION` (`Traversal::OUTBOUND`, `INBOUND`, `ANY`), `AQL::START`, `AQL::GRAPH`. The `$binds` parameter is passed by reference to accumulate internal *bind variables*.

```php
aqlTraversal
([
    AQL::VERTEX    => 'v' ,
    AQL::MIN       => 1   ,
    AQL::MAX       => 3   ,
    AQL::DIRECTION => Traversal::OUTBOUND ,
    AQL::START     => '@startVertex'       ,
    AQL::GRAPH     => 'social'             ,
]) ;
// "FOR v IN 1..3 OUTBOUND @startVertex GRAPH 'social'"
```

Official docs: [Graph traversals](https://docs.arangodb.com/stable/aql/graphs/traversals/).

### `aqlTraversalRange()`

```php
function aqlTraversalRange( ... ) : string
```

Builds the `<min>..<max>` fragment (e.g. `1..1`, `1..5`, `..2`, `3..`). Used internally by `aqlTraversal()` for the traversal scope; exposed independently for cases where you need to compute the range separately.

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

Official docs: [`WITH`](https://docs.arangodb.com/stable/aql/high-level-operations/with/).

## See also

- [Building an AQL query step by step](aql-building-queries.md) — pedagogical assembly narrative.
- [Operators `db/operators/`](aql-operators.md) — catalog of the 42 operators consumed by `aqlFilter`.
- [AQL helpers `db/helpers/`](../db-helpers.md) — value encoding, CUD sub-expressions, *field builders*.
- [Bind variables `db/binds/`](../db-binds.md) — safe injection.
- [Official AQL documentation — high-level operations](https://docs.arangodb.com/stable/aql/high-level-operations/).
