# Building an AQL query step by step

This page explains how to compose an AQL query using the standalone functions of the [`src/oihana/arango/db/operations/`](../../../src/oihana/arango/db/operations/) folder and the operators in [`db/operators/`](../../../src/oihana/arango/db/operators/). It is the pedagogical entry point for the `db/` layer; the full catalogs are in the [AQL operations](aql-operations.md), [Operators](aql-operators.md), [String functions](aql-functions-strings.md) and following pages.

## The mental model

`oihana/php-arango` does not provide an object *query builder* like `$qb->from(...)->where(...)->select(...)`. An AQL query here is a **text built by concatenation** of fragments produced by namespace functions.

Each AQL operation (`FOR`, `FILTER`, `SORT`, `LIMIT`, `RETURN`, ...) has its `aql*` function that produces the corresponding substring. You assemble these substrings manually via `sprintf`, `compile()`, or simple concatenation. Dynamic values go through [`aqlBind()`](../db/binds.md) to stay safe.

This approach has two benefits: you read in PHP code exactly the same structure as the final AQL query (no abstraction that "hides" the SQL), and you can compose freely without fighting a restrictive API.

## Canonical operation order

An AQL query follows a stable grammar. The order of operations inside a `FOR` loop:

```
FOR <var> IN <expression>           // iterate over a collection or sub-query
   [ SEARCH <expr>  ]               // optional — indexed filter via ArangoSearch view
   [ OPTIONS { ... } ]              // optional — index hint, cache, etc.
   [ FILTER <expr>  ]*              // restrict the stream (as many as needed)
   [ LET <name> = <expr> ]*         // intermediate variable
   [ COLLECT ... INTO ... ]?        // aggregation
   [ SORT <expr> ASC|DESC ]?        // sort
   [ LIMIT <offset>, <count> ]?     // pagination
RETURN <expr>                       // output — required at the top level
```

Modification operations (`INSERT`, `UPDATE`, `REPLACE`, `UPSERT`, `REMOVE`) typically replace the final `RETURN` in a write loop.

## Example 1 — Simple read query

The echo of the whole chain — a minimal `FOR ... RETURN`:

```php
use function oihana\arango\db\operations\aqlFor    ;
use function oihana\arango\db\operations\aqlReturn ;
use oihana\arango\enums\AQL ;

$query = aqlFor
([
    AQL::DOC_REF => 'doc'   ,
    AQL::IN      => 'users' ,
])
. ' ' . aqlReturn( 'doc' ) ;

// "FOR doc IN users RETURN doc"
```

`aqlFor()` consumes an array of `AQL::*` keys and produces the fragment `FOR doc IN users`. `aqlReturn()` accepts a string directly. Concatenation is done with `' '` or with `compile([...])` from the `oihana/php-core` package.

## Example 2 — Filter, sort, limit

Add the three most common operations:

```php
use function oihana\arango\db\operations\aqlFilter ;
use function oihana\arango\db\operations\aqlSort   ;
use function oihana\arango\db\operations\aqlLimit  ;
use function oihana\arango\db\operators\equal      ;
use function oihana\arango\db\operators\greaterThan ;
use function oihana\arango\db\binds\aqlBind        ;

$binds = [] ;

$query = implode( ' ' ,
[
    aqlFor( [ AQL::DOC_REF => 'doc' , AQL::IN => 'users' ] ) ,
    aqlFilter
    ([
        equal      ( 'doc.active' , aqlBind( true , $binds , 'active' ) ) ,
        greaterThan( 'doc.age'    , aqlBind( 18   , $binds , 'minAge' ) ) ,
    ]) ,
    aqlSort  ( 'doc.created DESC'   ) ,
    aqlLimit ( 0 , 50               ) ,
    aqlReturn( 'doc'                ) ,
]) ;

// FOR doc IN users
//   FILTER doc.active == @active && doc.age > @minAge
//   SORT doc.created DESC
//   LIMIT 0, 50
//   RETURN doc

// $binds === [ 'active' => true , 'minAge' => 18 ]
```

Three takeaways:

1. **`aqlFilter()`** accepts a single string or an array of conditions; an array is joined by default with `&&`. Operators (`equal`, `greaterThan`, ...) produce these conditions as strings `'doc.x == @bind'`.
2. ***Bind variables*** are accumulated in `$binds` by reference at every `aqlBind()` call. The final array is passed to `prepare()` on the `ArangoDB` instance.
3. **`aqlSort()`** accepts raw AQL text. For dynamic sorting, the `aqlAsc`, `aqlDesc`, `aqlSort` operations accept a field name and direction.

## Example 3 — Modification loop

The `FOR ... UPDATE` pattern:

```php
use function oihana\arango\db\operations\aqlUpdate ;

$query = implode( ' ' ,
[
    aqlFor( [ AQL::DOC_REF => 'doc' , AQL::IN => 'users' ] ) ,
    aqlFilter
    ([
        equal( 'doc.status' , aqlBind( 'pending' , $binds , 'status' ) ) ,
    ]) ,
    aqlUpdate
    ([
        AQL::KEY        => 'doc' ,
        AQL::WITH       => [ 'status' => aqlBind( 'active' , $binds , 'newStatus' ) ] ,
        AQL::COLLECTION => 'users' ,
    ]) ,
]) ;
```

For `UPDATE`, `REPLACE`, `INSERT`, `UPSERT` and `REPSERT`, the argument is an array of `AQL::*` keys (`KEY`, `WITH`, `COLLECTION`, `OPTIONS`, ...). The result generally doesn't need an explicit `RETURN` — ArangoDB exposes the `OLD` and `NEW` pseudo-variables on output if needed.

## Example 4 — Graph traversal

The pattern `FOR v, e, p IN min..max <DIRECTION> <start> GRAPH 'g'` is encapsulated by `aqlTraversal()`:

```php
use function oihana\arango\db\operations\aqlTraversal ;
use oihana\arango\db\enums\Traversal ;

$query = aqlTraversal
([
    AQL::VERTEX    => 'v'                                                ,
    AQL::EDGE      => 'e'                                                ,
    AQL::PATH      => 'p'                                                ,
    AQL::MIN       => 1                                                  ,
    AQL::MAX       => 3                                                  ,
    AQL::DIRECTION => Traversal::OUTBOUND                                ,
    AQL::START     => aqlBind( 'users/42' , $binds , 'startVertex' )    ,
    AQL::GRAPH     => 'social'                                           ,
])
. ' ' . aqlFilter( equal( 'v.active' , 'true' ) )
. ' ' . aqlReturn( 'v' ) ;

// FOR v, e, p IN 1..3 OUTBOUND @startVertex GRAPH 'social'
//   FILTER v.active == true
//   RETURN v
```

## The role of operators

The [`db/operators/`](../../../src/oihana/arango/db/operators/) folder provides 42 functions that produce a **predicate** as a string — `'doc.x == doc.y'`, `'doc.age > 18'`, `'doc.role IN ["admin", "owner"]'`, etc.

Five families:

- **Simple comparison** — `equal`, `notEqual`, `greaterThan`, `greaterThanOrEqual`, `lessThan`, `lessThanOrEqual`, `in`, `notIn`, `isLike`, `notLike`, `isMatch`, `notMatch`.
- **`ALL` quantifiers** — `allEqual`, `allGreaterThan`, ... — true if **all** elements on the left side satisfy the comparison.
- **`ANY` quantifiers** — `anyEqual`, `anyGreaterThan`, ... — true if **at least one** element satisfies.
- **`NONE` quantifiers** — `noneEqual`, `noneGreaterThan`, ... — true if **no** element satisfies.
- **Logical** — `logicalAnd`, `logicalOr`, `logicalNot`, `ternary`, `nullish`, `rangeOperator`.

The full catalog is in [Operators](aql-operators.md). All these operators simply return a string and can therefore be chained inside an `aqlFilter()`, an `aqlReturn(['result' => ...])`, or any other AQL insertion point.

## The role of functions

The [`db/functions/`](../../../src/oihana/arango/db/functions/) folder contains 144 functions that match the native AQL functions — `CONCAT`, `LOWER`, `DATE_NOW`, `COUNT`, `SUM`, etc. — distributed across five sub-folders: `strings/`, `dates/`, `numerics/`, `arrays/`, `documents/`.

Each PHP function takes one or more arguments (typically a reference to a field, e.g. `'doc.name'`) and returns the string `LOWER(doc.name)`. They are used in predicates or projections.

```php
use function oihana\arango\db\functions\strings\concat ;
use function oihana\arango\db\functions\strings\lower  ;
use function oihana\arango\db\functions\dates\dateNow  ;

aqlFilter( equal( lower( 'doc.email' ) , aqlBind( strtolower( $email ) , $binds , 'email' ) ) ) ;
// FILTER LOWER(doc.email) == @email

aqlReturn( [
    'fullName' => concat( 'doc.firstName' , "' '" , 'doc.lastName' ) ,
    'now'      => dateNow() ,
] ) ;
// RETURN { fullName: CONCAT(doc.firstName, ' ', doc.lastName), now: DATE_NOW() }
```

The full catalogs, by type, are in the [String functions](aql-functions-strings.md), [Date functions](aql-functions-dates.md), [Numeric functions](aql-functions-numerics.md), [Array functions](aql-functions-arrays.md), [Bit functions](aql-functions-bit.md) and [Document and check functions](aql-functions-checks.md) pages.

## Execute the final query

Once the query is assembled and the *bind variables* accumulated, pass them to `ArangoDB::prepare()` then execute:

```php
$db
    ->prepare ( [ 'query' => $query , 'bindVars' => $binds ] )
    ->execute () ;

$results = $db->getDocuments() ;
```

See [Quickstart `ArangoDB`](../db/quickstart.md) for the detail of execution and hydration methods.

## Beyond *manual* — high-level models

For standardized CRUD operations (list, read, create, update, delete a document from a collection), you don't write all this code manually: the [`Documents`](../models.md) model composes it for you from the `AQL::FIELDS`, `AQL::FILTERS`, `AQL::EDGES`, `AQL::JOINS` declarations. You fall back on the manual composition described here in two cases:

- A query **too specific** to fit the generic model (custom aggregation, complex joins, specialized traversals).
- A **custom trait** plugged into an existing model to add a business operation.

Understanding manual composition is therefore useful even when consuming the models: you read what they produce.

## See also

- [AQL operations `db/operations/`](aql-operations.md) — full catalog of the 21 operations.
- [Operators `db/operators/`](aql-operators.md) — catalog of the 42 operators.
- [String / date / numeric / array / document functions](aql-functions-strings.md).
- [AQL helpers `db/helpers/`](../db/helpers.md) — value encoding and fragment composition.
- [Bind variables `db/binds/`](../db/binds.md) — safe injection.
- [Official AQL documentation — high-level operations](https://docs.arangodb.com/stable/aql/high-level-operations/).
