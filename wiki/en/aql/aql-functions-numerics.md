# Numeric functions `db/functions/numerics/`

The [`api/src/oihana/arango/db/functions/numerics/`](../../../../api/src/oihana/arango/db/functions/numerics/) sub-folder groups **31 functions** that match the native AQL *numeric functions* — scalar computations, trigonometry, logarithms, array aggregations, range generation.

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
| Vectors | `cosSimilarity` |

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

Similarity measure between two vectors (each being an array of numbers). Used in combination with `vector` indexes for similarity search (*embeddings*).

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
