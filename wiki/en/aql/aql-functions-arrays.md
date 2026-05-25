# Array functions `db/functions/arrays/`

The [`src/oihana/arango/db/functions/arrays/`](../../../src/oihana/arango/db/functions/arrays/) sub-folder groups **19 functions** that match the native AQL *array functions* — counting, indexed access, ordering, modification, removal.

> Numeric aggregation functions on arrays (`SUM`, `AVERAGE`, `MIN`, `MAX`, `MEDIAN`, `PERCENTILE`, `PRODUCT`) live in [Numeric functions](aql-functions-numerics.md) — historically classified by ArangoDB on the *numeric* side.

## Summary

| Category | Functions |
|---|---|
| Counting | `count`, `countDistinct`, `length` |
| Indexed access | `first`, `last`, `nth`, `position` |
| Ordering | `reverse`, `sorted`, `sortedUnique`, `unique`, `slice` |
| Modification | `pop`, `shift`, `push`, `unshift`, `append` |
| Removal | `removeValue`, `removeValues` |

## Counting

| Function | Signature | AQL output |
|---|---|---|
| `count` | `(mixed $expression)` | `COUNT(<expr>)` |
| `countDistinct` | `(mixed $anyArray)` | `COUNT_DISTINCT(<array>)` |
| `length` | `(mixed $expression)` | `LENGTH(<expr>)` |

`count` and `length` are aliases in AQL. Pick whichever reads best in the context.

```php
use function oihana\arango\db\functions\arrays\count         ;
use function oihana\arango\db\functions\arrays\countDistinct ;
use function oihana\arango\db\functions\arrays\length        ;

count        ( 'doc.tags'       ) ;     // "COUNT(doc.tags)"
countDistinct( 'doc.categories' ) ;     // "COUNT_DISTINCT(doc.categories)"
length       ( 'doc.items'      ) ;     // "LENGTH(doc.items)"
```

## Indexed access

| Function | Signature | AQL output |
|---|---|---|
| `first` | `(mixed $anyArray)` | `FIRST(<array>)` |
| `last` | `(mixed $anyArray)` | `LAST(<array>)` |
| `nth` | `(mixed $anyArray, int $position)` | `NTH(<array>, <position>)` |
| `position` | `(mixed $anyArray, int\|string $search, bool $returnIndex = false)` | `POSITION(<array>, <search>[, <returnIndex>])` |

`position` returns either a boolean (present or not), or the index if `$returnIndex` is `true`.

```php
use function oihana\arango\db\functions\arrays\first    ;
use function oihana\arango\db\functions\arrays\nth      ;
use function oihana\arango\db\functions\arrays\position ;

first   ( 'doc.tags'                   ) ;     // "FIRST(doc.tags)"
nth     ( 'doc.scores' , 2             ) ;     // "NTH(doc.scores, 2)"
position( 'doc.tags' , "'featured'"    ) ;     // "POSITION(doc.tags, 'featured')"
position( 'doc.tags' , "'featured'" , true ) ; // "POSITION(doc.tags, 'featured', true)"
```

## Ordering

| Function | Signature | AQL output |
|---|---|---|
| `reverse` | `(mixed $anyArray)` | `REVERSE(<array>)` |
| `sorted` | `(mixed $anyArray)` | `SORTED(<array>)` |
| `sortedUnique` | `(mixed $anyArray)` | `SORTED_UNIQUE(<array>)` |
| `unique` | `(mixed $anyArray)` | `UNIQUE(<array>)` |
| `slice` | `(mixed $anyArray, int $start, ?int $length)` | `SLICE(<array>, <start>[, <length>])` |

`sorted` and `sortedUnique` produce a sorted array (with or without deduplication). `unique` deduplicates without sorting (preserves insertion order).

```php
use function oihana\arango\db\functions\arrays\sorted ;
use function oihana\arango\db\functions\arrays\unique ;
use function oihana\arango\db\functions\arrays\slice  ;

sorted( 'doc.scores'         ) ;     // "SORTED(doc.scores)"
unique( 'doc.tags'           ) ;     // "UNIQUE(doc.tags)"
slice ( 'doc.items' , 0 , 5  ) ;     // "SLICE(doc.items, 0, 5)"
```

## Modification

| Function | Signature | AQL output |
|---|---|---|
| `pop` | `(mixed $anyArray)` | `POP(<array>)` (removes last) |
| `shift` | `(mixed $anyArray)` | `SHIFT(<array>)` (removes first) |
| `push` | `(mixed $anyArray, mixed $value, bool $unique = false)` | `PUSH(<array>, <value>[, <unique>])` (appends) |
| `unshift` | `(mixed $anyArray, mixed $value, bool $unique = false)` | `UNSHIFT(<array>, <value>[, <unique>])` (prepends) |
| `append` | `(mixed $anyArray, mixed $values, bool $unique = false)` | `APPEND(<array>, <values>[, <unique>])` (appends an array) |

The `$unique = true` parameter prevents adding an element already present in the array.

```php
use function oihana\arango\db\functions\arrays\push   ;
use function oihana\arango\db\functions\arrays\append ;

push  ( 'doc.tags' , "'urgent'" , true        ) ;     // "PUSH(doc.tags, 'urgent', true)"
append( 'doc.tags' , [ "'a'" , "'b'" ]        ) ;     // "APPEND(doc.tags, ['a', 'b'])"
```

## Removal

| Function | Signature | AQL output |
|---|---|---|
| `removeValue` | `(string $anyArray, mixed $value, ?int $limit)` | `REMOVE_VALUE(<array>, <value>[, <limit>])` |
| `removeValues` | `(string $anyArray, string $values)` | `REMOVE_VALUES(<array>, <values>)` |

`removeValue` removes all occurrences (or the first `$limit`) of a value. `removeValues` removes all occurrences of several values.

## Typical composition

List documents with exactly 3 *tags*, at least one of which is `'urgent'`:

```php
use function oihana\arango\db\operators\equal    ;
use function oihana\arango\db\operators\anyEqual ;
use function oihana\arango\db\operations\aqlFilter ;
use function oihana\arango\db\functions\arrays\count ;

aqlFilter
([
    equal   ( count( 'doc.tags' ) , 3            ) ,
    anyEqual( 'doc.tags'          , "'urgent'"   ) ,
]) ;
// "FILTER COUNT(doc.tags) == 3 && doc.tags ANY == 'urgent'"
```

## See also

- [Numeric functions `db/functions/numerics/`](aql-functions-numerics.md) — numeric aggregations on arrays (`SUM`, `AVG`, `MIN`, `MAX`, `MEDIAN`).
- [Quantified operators](aql-operators.md#all-quantified-operators) — `ALL`, `ANY`, `NONE` on arrays.
- [Building an AQL query step by step](aql-building-queries.md).
- [Official AQL documentation — Array functions](https://docs.arangodb.com/stable/aql/functions/array/).
