# Operators `db/operators/`

The [`src/oihana/arango/db/operators/`](../../../src/oihana/arango/db/operators/) folder provides the **42 functions** that produce an AQL **predicate** — a string like `'doc.x == doc.y'` that plugs into an `aqlFilter()`, an `aqlReturn()`, or any other place a boolean expression is expected.

All functions share the same base signature `(mixed $leftOperand, mixed $rightOperand) : string`, with the exception of unary logical operators (`logicalNot`) and a few special operators (`ternary`, `nullish`, `rangeOperator`).

## Families

| Family | Count | Semantics |
|---|---|---|
| Simple comparison | 12 | Compare two scalar values. |
| `ALL` (quantified) | 8 | True if **all** elements of the left array satisfy the comparison. |
| `ANY` (quantified) | 8 | True if **at least one** element satisfies. |
| `NONE` (quantified) | 8 | True if **no** element satisfies. |
| Logical and conditional | 6 | `AND`, `OR`, `NOT`, ternary, *nullish*, range. |

## Simple comparison

The 12 base comparators. All produce `<left> <op> <right>`.

| Function | AQL operator | Example |
|---|---|---|
| `equal` | `==` | `equal( 'doc.age' , 18 )` → `"doc.age == 18"` |
| `notEqual` | `!=` | `notEqual( 'doc.status' , "'closed'" )` |
| `greaterThan` | `>` | `greaterThan( 'doc.price' , 100 )` |
| `greaterThanOrEqual` | `>=` | `greaterThanOrEqual( 'doc.score' , 50 )` |
| `lessThan` | `<` | `lessThan( 'doc.qty' , 10 )` |
| `lessThanOrEqual` | `<=` | `lessThanOrEqual( 'doc.weight' , 25 )` |
| `in` | `IN` | `in( 'doc.role' , [ 'admin' , 'owner' ] )` |
| `notIn` | `NOT IN` | `notIn( 'doc.status' , [ 'closed' , 'archived' ] )` |
| `isLike` | `LIKE` | `isLike( 'doc.name' , "'%john%'" )` |
| `notLike` | `NOT LIKE` | `notLike( 'doc.code' , "'TMP%'" )` |
| `isMatch` | `=~` | `isMatch( 'doc.email' , "'^[a-z]+@'" )` (regex) |
| `notMatch` | `!~` | `notMatch( 'doc.email' , "'@spam\\\\.'" )` |

Official docs: [Comparison operators](https://docs.arangodb.com/stable/aql/operators/#comparison-operators).

## `ALL` quantified operators

True if **all** elements of the left array satisfy the comparison. Produces `<left> ALL <op> <right>`.

| Function | AQL | Use case |
|---|---|---|
| `allEqual` | `[...] ALL == x` | Homogeneous list. |
| `allNotEqual` | `[...] ALL != x` | No element is `x`. |
| `allGreaterThan` | `[...] ALL > x` | All values above the threshold. |
| `allGreaterThanOrEqual` | `[...] ALL >= x` | At least the threshold everywhere. |
| `allLessThan` | `[...] ALL < x` | All values below the threshold. |
| `allLessThanOrEqual` | `[...] ALL <= x` | Cap respected everywhere. |
| `allIn` | `[...] ALL IN [...]` | All values in the *whitelist*. |
| `allNotIn` | `[...] ALL NOT IN [...]` | No value in the *blacklist*. |

```php
allGreaterThan( 'doc.scores' , 50 ) ;
// "doc.scores ALL > 50"
```

## `ANY` quantified operators

True if **at least one** element satisfies. Produces `<left> ANY <op> <right>`.

| Function | AQL | Use case |
|---|---|---|
| `anyEqual` | `[...] ANY == x` | At least one value is `x`. |
| `anyNotEqual` | `[...] ANY != x` | At least one value differs. |
| `anyGreaterThan` | `[...] ANY > x` | At least one element above the threshold. |
| `anyGreaterThanOrEqual` | `[...] ANY >= x` | At least one element reaches the threshold. |
| `anyLessThan` | `[...] ANY < x` | At least one element below the threshold. |
| `anyLessThanOrEqual` | `[...] ANY <= x` | At least one element at or below. |
| `anyIn` | `[...] ANY IN [...]` | At least one element in the list. |
| `anyNotIn` | `[...] ANY NOT IN [...]` | At least one element out of the list. |

```php
anyIn( 'doc.tags' , [ 'urgent' , 'critical' ] ) ;
// "doc.tags ANY IN [\"urgent\",\"critical\"]"
```

## `NONE` quantified operators

True if **no** element satisfies. Produces `<left> NONE <op> <right>`.

| Function | AQL | Use case |
|---|---|---|
| `noneEqual` | `[...] NONE == x` | No element is `x`. |
| `noneNotEqual` | `[...] NONE != x` | All elements are `x` (equivalent to `allEqual`). |
| `noneGreaterThan` | `[...] NONE > x` | No element above the threshold. |
| `noneGreaterThanOrEqual` | `[...] NONE >= x` | No element reaches the threshold. |
| `noneLessThan` | `[...] NONE < x` | No element below the threshold. |
| `noneLessThanOrEqual` | `[...] NONE <= x` | No element at or below. |
| `noneIn` | `[...] NONE IN [...]` | No element in the *blacklist*. |
| `noneNotIn` | `[...] NONE NOT IN [...]` | All elements in the *whitelist* (equivalent to `allIn`). |

```php
noneEqual( 'doc.statuses' , "'closed'" ) ;
// "doc.statuses NONE == 'closed'"
```

Official docs for `ALL` / `ANY` / `NONE`: [Array comparison operators](https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators).

## Logical and conditional operators

| Function | Signature | Output | Semantics |
|---|---|---|---|
| `logicalAnd` | `(mixed $a , mixed $b)` | `<a> && <b>` | Conjunction. |
| `logicalOr` | `(mixed $a , mixed $b)` | `<a> \|\| <b>` | Disjunction. |
| `logicalNot` | `(mixed $a)` | `!<a>` | Negation. |
| `ternary` | `(mixed $cond , mixed $then , mixed $else)` | `<cond> ? <then> : <else>` | Ternary conditional. |
| `nullish` | `(mixed $a , mixed $fallback)` | `<a> ?? <fallback>` | Coalescence: `$fallback` if `$a` is `null`. |
| `rangeOperator` | `(mixed $low , mixed $high)` | `<low>..<high>` | Inclusive range (useful for `aqlTraversalRange` or integer iterations). |

```php
logicalAnd( equal( 'doc.x' , 1 ) , greaterThan( 'doc.y' , 0 ) ) ;
// "doc.x == 1 && doc.y > 0"

ternary( equal( 'doc.role' , "'admin'" ) , "'full'" , "'limited'" ) ;
// "doc.role == 'admin' ? 'full' : 'limited'"

nullish( 'doc.nickname' , "'Anonymous'" ) ;
// "doc.nickname ?? 'Anonymous'"

rangeOperator( 1 , 10 ) ;
// "1..10"
```

To combine several predicates inside an `aqlFilter()`, it is usually clearer to pass an array and let `aqlFilter` join them with `&&` or `||`:

```php
aqlFilter
([
    equal      ( 'doc.active' , 'true' )      ,
    greaterThan( 'doc.score'  , 50      )      ,
    in         ( 'doc.role'   , [ 'admin' , 'owner' ] ) ,
] , Logic::AND ) ;
```

Official docs for logical and ternary operators: [Logical operators](https://docs.arangodb.com/stable/aql/operators/#logical-operators).

## Behavior with values

All functions simply produce a **string** and do not validate their arguments. It is the caller's responsibility to make sure the operands are:

- either AQL references (`'doc.field'`, `'@bind'`, `'users/42'`) — passed as-is;
- or values encoded via [`aqlValue()`](../db/helpers.md#aqlvalue--the-foundation) — preferable as soon as there is a risk of ambiguity;
- or *placeholders* produced by [`aqlBind()`](../db/binds.md) — recommended for any dynamic value.

Mixing raw PHP values directly with operators without going through the helpers is possible but discouraged: the produced string will not be escaped and will expose the query to injection.

## See also

- [Building an AQL query step by step](aql-building-queries.md) — chaining examples for operators + operations.
- [AQL operations `db/operations/`](aql-operations.md) — where these predicates are consumed (`aqlFilter`, `aqlPrune`, `aqlSearch`).
- [AQL helpers `db/helpers/`](../db/helpers.md) — `aqlValue` to encode values.
- [Bind variables `db/binds/`](../db/binds.md) — safe injection.
- [Official AQL documentation — operators](https://docs.arangodb.com/stable/aql/operators/).
