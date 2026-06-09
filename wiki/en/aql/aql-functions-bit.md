# Bit functions `db/functions/bit/`

The [`src/oihana/arango/db/functions/bit/`](../../../src/oihana/arango/db/functions/bit/) sub-folder groups the **12 functions** that match the native AQL *bit functions* — bitwise logic, shifts, population count, and bitstring conversions. Each helper returns the `BIT_FUNCTION(args)` string, ready to drop into a `FILTER` / `SORT` / projection.

> All AQL bit operations work on **unsigned integers up to 32 bits** — input values must be in the range `0 … 2³² - 1`, and any `bits` argument in `0 … 32`.

## Summary

| Category | Functions |
|---|---|
| Logic | `bitAnd`, `bitOr`, `bitXor`, `bitNegate` |
| Inspection | `bitTest`, `bitPopcount` |
| Shifts | `bitShiftLeft`, `bitShiftRight` |
| Construction | `bitConstruct`, `bitDeconstruct` |
| String conversion | `bitToString`, `bitFromString` |

## Reference

| Function | Signature | AQL output |
|---|---|---|
| `bitAnd` | `(string\|int\|array $values, string\|int\|null $value2 = null)` | `BIT_AND([…])` or `BIT_AND(a, b)` |
| `bitOr` | `(string\|int\|array $values, string\|int\|null $value2 = null)` | `BIT_OR([…])` or `BIT_OR(a, b)` |
| `bitXor` | `(string\|int\|array $values, string\|int\|null $value2 = null)` | `BIT_XOR([…])` or `BIT_XOR(a, b)` |
| `bitNegate` | `(string\|int $value, string\|int $bits)` | `BIT_NEGATE(<v>, <bits>)` |
| `bitTest` | `(string\|int $value, string\|int $index)` | `BIT_TEST(<v>, <index>)` |
| `bitPopcount` | `(string\|int $value)` | `BIT_POPCOUNT(<v>)` |
| `bitShiftLeft` | `(string\|int $value, string\|int $shift, string\|int $bits)` | `BIT_SHIFT_LEFT(<v>, <shift>, <bits>)` |
| `bitShiftRight` | `(string\|int $value, string\|int $shift, string\|int $bits)` | `BIT_SHIFT_RIGHT(<v>, <shift>, <bits>)` |
| `bitConstruct` | `(string\|array $positions)` | `BIT_CONSTRUCT([…])` |
| `bitDeconstruct` | `(string\|int $value)` | `BIT_DECONSTRUCT(<v>)` |
| `bitToString` | `(string\|int $value, string\|int $bits)` | `BIT_TO_STRING(<v>, <bits>)` |
| `bitFromString` | `(string $bitstring)` | `BIT_FROM_STRING("<bitstring>")` |

`bitAnd` / `bitOr` / `bitXor` accept **either** a single array of numbers **or** two operands (pass the second argument). PHP arrays (for the array form, and for `bitConstruct`) are emitted as JSON literals; the `bitFromString` bitstring is emitted as a quoted string literal. Every other argument is passed through as a raw AQL expression, so columns like `doc.flags` work directly.

`bitConstruct` ⇄ `bitDeconstruct` and `bitToString` ⇄ `bitFromString` are inverse pairs.

## Examples

```php
use function oihana\arango\db\functions\bit\bitAnd        ;
use function oihana\arango\db\functions\bit\bitShiftLeft   ;
use function oihana\arango\db\functions\bit\bitTest        ;
use function oihana\arango\db\functions\bit\bitFromString  ;

bitAnd([1, 4, 8, 16]);      // "BIT_AND([1,4,8,16])"      → 0
bitAnd(127, 255);           // "BIT_AND(127,255)"         → 127
bitShiftLeft(1, 4, 8);      // "BIT_SHIFT_LEFT(1,4,8)"    → 16
bitTest('doc.flags', 3);    // "BIT_TEST(doc.flags,3)"
bitFromString('0101');      // "BIT_FROM_STRING(\"0101\")" → 5
```

Filter documents whose `flags` attribute has a given bit set:

```php
use function oihana\arango\db\functions\bit\bitTest ;
use function oihana\arango\db\operations\aqlFilter  ;

aqlFilter( bitTest( 'doc.flags' , 2 ) );
// "FILTER BIT_TEST(doc.flags, 2)"
```

## See also

- [Numeric functions `db/functions/numerics/`](aql-functions-numerics.md) — arithmetic, trigonometry, vectors.
- [Building an AQL query step by step](aql-building-queries.md).
- [Official AQL documentation — Bit functions](https://docs.arangodb.com/stable/aql/functions/bit/).
