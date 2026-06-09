# Numeric functions `db/functions/numerics/`

The [`src/oihana/arango/db/functions/numerics/`](../../../src/oihana/arango/db/functions/numerics/) sub-folder groups **35 functions** that match the native AQL *numeric functions* — scalar computations, trigonometry, logarithms, array aggregations, range generation, and vector distances.

## Summary

| Category | Functions |
|---|---|
| Basic arithmetic | `abs`, `ceil`, `floor`, `round`, `sqrt` |
| Powers and exponentials | `pow`, `exp`, `exp2` |
| Logarithms | `log`, `log10`, `log2` |
| Trigonometry | `sin`, `cos`, `tan`, `asin`, `acos`, `atan`, `atan2` |
| Angle conversion | `degrees`, `radians` |
| Constants and random | `pi`, `rand` |
| Array aggregation | `average`, `max`, `min`, `median`, `percentile`, `product`, `sum` |
| Sequence | `range` |
| Vectors | `cosSimilarity`, `l1Distance`, `l2Distance`, `approxNearCosine`, `approxNearL2` |

## Basic arithmetic

| Function | Signature | AQL output |
|---|---|---|
| `abs` | `(string\|int\|float $value)` | `ABS(<value>)` |
| `ceil` | `(string\|int\|float $value)` | `CEIL(<value>)` |
| `floor` | `(string\|int\|float $value)` | `FLOOR(<value>)` |
| `round` | `(string\|int\|float $value)` | `ROUND(<value>)` |
| `sqrt` | `(string\|int\|float $value)` | `SQRT(<value>)` |

```php
use function oihana\arango\db\functions\numerics\abs   ;
use function oihana\arango\db\functions\numerics\round ;

abs  ( 'doc.balance' ) ;     // "ABS(doc.balance)"
round( 'doc.price'   ) ;     // "ROUND(doc.price)"
```

## Powers and exponentials

| Function | Signature | AQL output |
|---|---|---|
| `pow` | `(mixed $base, int $exp)` | `POW(<base>, <exp>)` |
| `exp` | `(string\|int\|float $value)` | `EXP(<value>)` (e^x) |
| `exp2` | `(string\|int\|float $value)` | `EXP2(<value>)` (2^x) |

## Logarithms

| Function | Signature | AQL output |
|---|---|---|
| `log` | `(string\|int\|float $value)` | `LOG(<value>)` (natural logarithm) |
| `log10` | `(string\|int\|float $value)` | `LOG10(<value>)` |
| `log2` | `(string\|int\|float $value)` | `LOG2(<value>)` |

## Trigonometry

All in radians. To work in degrees, wrap with `radians()` on input and `degrees()` on output.

| Function | Signature | AQL output |
|---|---|---|
| `sin` | `(string\|int\|float $value)` | `SIN(<value>)` |
| `cos` | `(string\|int\|float $value)` | `COS(<value>)` |
| `tan` | `(string\|int\|float $value)` | `TAN(<value>)` |
| `asin` | `(string\|int\|float $value)` | `ASIN(<value>)` |
| `acos` | `(string\|int\|float $value)` | `ACOS(<value>)` |
| `atan` | `(string\|int\|float $value)` | `ATAN(<value>)` |
| `atan2` | `(string\|int $y, string\|int $x)` | `ATAN2(<y>, <x>)` |

## Angle conversion

| Function | Signature | AQL output |
|---|---|---|
| `degrees` | `(string\|int\|float $rad)` | `DEGREES(<rad>)` |
| `radians` | `(string\|int\|float $deg)` | `RADIANS(<deg>)` |

## Constants and random

| Function | Signature | AQL output |
|---|---|---|
| `pi` | `()` | `PI()` |
| `rand` | `()` | `RAND()` (pseudo-random real between 0 and 1) |

## Array aggregation

These functions take a `mixed $anyArray` argument (reference to an array field or expression that produces one) and compute a statistic.

| Function | Signature | AQL output |
|---|---|---|
| `average` | `(mixed $anyArray)` | `AVERAGE(<array>)` |
| `max` | `(mixed $anyArray)` | `MAX(<array>)` |
| `min` | `(mixed $anyArray)` | `MIN(<array>)` |
| `median` | `(mixed $anyArray)` | `MEDIAN(<array>)` |
| `percentile` | `(mixed $numArray, int $position, ?string $method)` | `PERCENTILE(<array>, <position>[, <method>])` |
| `product` | `(mixed $numArray)` | `PRODUCT(<array>)` |
| `sum` | `(mixed $numArray)` | `SUM(<array>)` |

```php
use function oihana\arango\db\functions\numerics\average ;
use function oihana\arango\db\functions\numerics\sum     ;

average( 'doc.scores' ) ;        // "AVERAGE(doc.scores)"
sum    ( 'doc.amounts' ) ;       // "SUM(doc.amounts)"
```

## Sequence

| Function | Signature | AQL output |
|---|---|---|
| `range` | `(int $start, int $stop, float $step = 1.0)` | `RANGE(<start>, <stop>[, <step>])` |

Produces an array of numbers in the interval. Useful in a `FOR i IN RANGE(1, 10)` to iterate over integers.

## Vectors

| Function | Signature | AQL output |
|---|---|---|
| `cosSimilarity` | `(string\|int $x, string\|int $y)` | `COSINE_SIMILARITY(<x>, <y>)` |
| `l1Distance` | `(string\|int $x, string\|int $y)` | `L1_DISTANCE(<x>, <y>)` |
| `l2Distance` | `(string\|int $x, string\|int $y)` | `L2_DISTANCE(<x>, <y>)` |
| `approxNearCosine` | `(string\|int $x, string\|int $y, ?int $nProbe = null)` | `APPROX_NEAR_COSINE(<x>, <y>[, {"nProbe":N}])` |
| `approxNearL2` | `(string\|int $x, string\|int $y, ?int $nProbe = null)` | `APPROX_NEAR_L2(<x>, <y>[, {"nProbe":N}])` |

`cosSimilarity`, `l1Distance` and `l2Distance` compute an **exact** measure between two vectors (each an array of numbers) and need no index.

`approxNearCosine` and `approxNearL2` compute an **approximate** nearest-neighbour score that is *accelerated by a `vector` index* — one operand must reference the indexed attribute, the other is the query vector. They are the building blocks of similarity search over *embeddings* (RAG, recommendations). The optional `nProbe` widens the search (more centroids probed → more accurate, slower).

> The metric must match the `VectorIndex` metric: `approxNearCosine` ⇄ a `cosine` index sorted **`DESC`** (closer to 1 = nearer), `approxNearL2` ⇄ an `l2` index sorted **`ASC`** (closer to 0 = nearer). Vector indexes are an **experimental** ArangoDB feature (server started with `--experimental-vector-index`).

For the full nearest-neighbour query, prefer the dedicated [`aqlVectorSearch()`](aql-operations.md#aqlvectorsearch--approximate-nearest-neighbour-search) operation, which wires the function, the sort direction and the `LIMIT` together for you:

```php
use function oihana\arango\db\operations\aqlVectorSearch ;

aqlVectorSearch( collection: 'items', attribute: 'embedding', vector: '@query', limit: 10 ) ;
// "FOR doc IN items SORT APPROX_NEAR_COSINE(doc.embedding,@query) DESC LIMIT 10 RETURN doc"
```

## Typical composition

Compute the average, min and max of an array of scores, all in the same `RETURN`:

```php
use function oihana\arango\db\operations\aqlReturn      ;
use function oihana\arango\db\helpers\aqlDocument       ;
use function oihana\arango\db\functions\numerics\average ;
use function oihana\arango\db\functions\numerics\min     ;
use function oihana\arango\db\functions\numerics\max     ;

aqlReturn
(
    aqlDocument
    ([
        'avg' => average( 'doc.scores' ) ,
        'min' => min    ( 'doc.scores' ) ,
        'max' => max    ( 'doc.scores' ) ,
    ])
) ;
// "RETURN {avg:AVERAGE(doc.scores),min:MIN(doc.scores),max:MAX(doc.scores)}"
```

## See also

- [Array functions `db/functions/arrays/`](aql-functions-arrays.md) — other array operations (`count`, `length`, `first`, `last`, ...).
- [Building an AQL query step by step](aql-building-queries.md).
- [Official AQL documentation — Numeric functions](https://docs.arangodb.com/stable/aql/functions/numeric/).
