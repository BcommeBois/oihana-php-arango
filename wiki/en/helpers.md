# Helpers `oihana\arango\helpers`

Small functional utilities (one function per file) living under
[`src/oihana/arango/helpers/`](../../src/oihana/arango/helpers/).

Not to be confused with [`oihana\arango\db\helpers`](../../src/oihana/arango/db/helpers/)
nor [`oihana\arango\db\operations`](../../src/oihana/arango/db/operations/),
which produce **AQL output** (`"FOR doc IN ..."`, `"doc.name DESC"`,
etc.). The helpers on this page work on the **input** side: textual
HTTP grammar, document identifiers, revision formats.

## Table of contents

- [Textual sort grammar](#textual-sort-grammar)
  - [`ascKey()`](#asckey)
  - [`descKey()`](#desckey)
  - [`sortKeys()`](#sortkeys)
  - [Anti-pattern: magic strings](#anti-pattern-magic-strings)
  - [Distinction with `aqlAsc` / `aqlDesc` / `aqlSort`](#distinction-with-aqlasc--aqldesc--aqlsort)
- [ArangoDB identifier parsing](#arangodb-identifier-parsing)
  - [`parseIdentifier()`](#parseidentifier)
  - [`parseKey()`](#parsekey)
  - [`parseCollection()`](#parsecollection)
- [`_rev` revision encoding](#_rev-revision-encoding)
  - [`decodeRevision()`](#decoderevision)
  - [`encodeRevision()`](#encoderevision)

---

## Textual sort grammar

The same grammar is consumed by two places in the framework:

1. The HTTP `?sort=` client parameter (`?sort=-created,name`).
2. The DI keys `AQL::SORT_DEFAULT` and `Arango::SORT` passed to a
   `Documents` model at runtime.

In both cases it is a **string** with the grammar:

```
<expression> := <token> ( ',' <token> )*
<token>      := [-] <field>
```

A leading `-` means descending order. No prefix: ascending.

This string is what `SortTrait::prepareSort` parses to produce the
final AQL `SORT ...`.

### `ascKey()`

```php
ascKey( string $key ) : string
```

Returns `$key` unchanged. The function exists for **symmetry** with
`descKey()` and to make intent explicit at call site.

```php
use function oihana\arango\helpers\ascKey;

ascKey( Prop::NAME ) ;  // 'name'
```

### `descKey()`

```php
descKey( string $key ) : string
```

Returns `$key` prefixed with `-`. Centralises the "minus = descending"
convention so we no longer write `'-' . Prop::X` by hand.

```php
use function oihana\arango\helpers\descKey;

descKey( Prop::CREATED ) ;  // '-created'
descKey( Prop::_KEY ) ;     // '-_key'
```

### `sortKeys()`

```php
sortKeys( string ...$keys ) : string
```

Composes a sort expression by joining tokens with a comma. Empty
tokens are **silently dropped** via
[`oihana\core\strings\compile()`](https://github.com/BcommeBois/oihana-php-core/blob/main/src/oihana/core/strings/compile.php),
which lets callers pass conditional tokens without `array_filter()`
boilerplate.

```php
use function oihana\arango\helpers\descKey;
use function oihana\arango\helpers\sortKeys;

sortKeys( descKey( Prop::CREATED ) )                         ; // '-created'
sortKeys( descKey( Prop::CREATED ) , Prop::NAME )            ; // '-created,name'
sortKeys( descKey( Prop::CREATED ) , descKey( Prop::NAME ) ) ; // '-created,-name'
sortKeys()                                                   ; // ''
```

### Anti-pattern: magic strings

To avoid — these forms mix typed constants with raw strings (`'-'`,
`','`), which is precisely what the project bans (general rule
**"no magic strings"**: any value assigned to a typed key goes through
a helper or a constant):

```php
// ❌ Never write
AQL::SORT_DEFAULT => '-' . Prop::CREATED ,
Arango::SORT      => '-' . Prop::CREATED . ',' . Prop::NAME ,
Arango::SORT      => '-' . Prop::CREATED . ',' . '-' . Prop::NAME ,
```

Correct equivalents:

```php
// ✅ Canonical form
AQL::SORT_DEFAULT => descKey( Prop::CREATED ) ,
Arango::SORT      => sortKeys( descKey( Prop::CREATED ) , Prop::NAME ) ,
Arango::SORT      => sortKeys( descKey( Prop::CREATED ) , descKey( Prop::NAME ) ) ,
```

### Distinction with `aqlAsc` / `aqlDesc` / `aqlSort`

The framework also exposes a **homonymous but different** trio living
in [`oihana\arango\db\operations`](../../src/oihana/arango/db/operations/):

| Helper                                       | Folder           | Emits                                       | When to use it |
|----------------------------------------------|------------------|---------------------------------------------|----------------|
| `ascKey` / `descKey` / `sortKeys`            | `helpers/`       | `'name'`, `'-created'`, `'-created,name'`   | DI (`AQL::SORT_DEFAULT`, `Arango::SORT`) — this is the **input grammar** |
| `aqlAsc` / `aqlDesc` / `aqlSort`             | `db/operations/` | `'doc.name ASC'`, `'SORT doc.name DESC'`    | Low-level builders that produce the **final AQL SORT** sent to ArangoDB |

Quick decision rule:

- Writing a **sort expression for an HTTP caller** or a **default value
  in DI** → `ascKey` / `descKey` / `sortKeys`.
- Composing **AQL by hand** inside a trait/model → `aqlAsc` / `aqlDesc`
  / `aqlSort`.

---

## ArangoDB identifier parsing

An ArangoDB *document handle* has the form `<collection>/<_key>` (e.g. `users/42`).
The `parseIdentifier` / `parseKey` / `parseCollection` trio splits this string without
manual `explode()` handling.

### `parseIdentifier()`

```php
parseIdentifier( ?string $id ) : ?array
```

Splits a *document handle* into its two components. Returns `null` if the input is
`null` or doesn't match the `<collection>/<key>` grammar.

```php
use function oihana\arango\helpers\parseIdentifier ;

parseIdentifier( 'users/42'    ) ;     // [ 'collection' => 'users' , 'key' => '42' ]
parseIdentifier( 'invalid'     ) ;     // null
parseIdentifier( null          ) ;     // null
```

### `parseKey()`

```php
parseKey( ?string $id ) : ?string
```

Returns only the `_key` of a *document handle*. Equivalent to the second component
of `parseIdentifier()`.

```php
use function oihana\arango\helpers\parseKey ;

parseKey( 'users/42' ) ;       // '42'
parseKey( 'invalid'  ) ;       // null
parseKey( null       ) ;       // null
```

### `parseCollection()`

```php
parseCollection( ?string $id ) : ?string
```

Returns only the collection name of a *document handle*.

```php
use function oihana\arango\helpers\parseCollection ;

parseCollection( 'users/42' ) ;        // 'users'
parseCollection( 'invalid'  ) ;        // null
parseCollection( null       ) ;        // null
```

**Typical usage pattern** — controller-side extraction when receiving a raw `_id`
as a parameter and you want either the `_key` for a lookup or the collection for
an access check:

```php
$id  = $args[ 'id' ] ?? null ;
$key = parseKey( $id ) ;

if ( $key === null )
{
    return $this->fail( HttpStatusCode::BAD_REQUEST , 'invalid_id' ) ;
}

$document = $this->model->get( [ Arango::ID => $key ] ) ;
```

---

## `_rev` revision encoding

The `_rev` field of an ArangoDB document encodes the date of the last write and an
incremental counter. The format is internal to ArangoDB and **should not be parsed
by hand**. The two helpers `decodeRevision` / `encodeRevision` expose the official
encoding for the rare cases where you need it (export, freshness comparison, audit).

### `decodeRevision()`

```php
decodeRevision( ?string $revision , bool $throwable = false ) : ?array
```

Decomposes a revision into its two components. Returns an array `[ 'date' => ... , 'count' => ... ]`
or `null` if the input is invalid (`$throwable = false`) or throws an exception
(`$throwable = true`).

```php
use function oihana\arango\helpers\decodeRevision ;

$parsed = decodeRevision( '_iVZdJZ--_S' ) ;
// [ 'date' => '2026-05-17T14:32:18.000Z' , 'count' => 1234 ]

decodeRevision( null )      ;           // null
decodeRevision( 'invalid' ) ;           // null
decodeRevision( 'invalid' , throwable: true ) ;  // throws
```

### `encodeRevision()`

```php
encodeRevision( string $date , ?int $count = null , bool $throwable = false ) : string
```

Encodes a date (and an optional counter) in the ArangoDB `_rev` format. Useful to
produce a synthetic `_rev` in tests, or to compare two dates in the format expected
by the engine.

```php
use function oihana\arango\helpers\encodeRevision ;

encodeRevision( '2026-05-17T14:32:18.000Z' , 1234 ) ;  // '_iVZdJZ--_S'
encodeRevision( '2026-05-17T14:32:18.000Z'        ) ;  // '_iVZdJZ--__'
```

**Don't** — use `_rev` for application logic (timestamp, write order). ArangoDB
**guarantees `_rev` uniqueness per document**, but doesn't guarantee it can be
interpreted as a monotonic timestamp. For business timestamping, add an explicit
`modified` field (the `Schema::MODIFIED` convention from the
[`oihana/php-schema`](dependencies.md) package, aligned with Schema.org).
