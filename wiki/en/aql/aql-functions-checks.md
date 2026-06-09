# Document and check functions

This page groups **45 functions** spread between the root of [`db/functions/`](../../../src/oihana/arango/db/functions/) (24 cross-cutting functions) and the [`db/functions/documents/`](../../../src/oihana/arango/db/functions/documents/) sub-folder (21 document functions). You'll find the *type checks*, the *casts*, the document operations and the database information functions.

## Summary

| Category | Functions |
|---|---|
| Type checks | `isArray`, `isBool`, `isDateString`, `isKey`, `isNull`, `isNumber`, `isObject`, `isString` |
| Type conversions | `toArray`, `toBool`, `toNumber`, `toString` |
| Type inspection | `typeName` |
| Conditional choice | `firstDocument`, `firstList`, `notNull` |
| Documents (root) | `document`, `checkDocument`, `decodeRev` |
| Documents (subfolder) | `attributes`, `count`, `entries`, `has`, `isSameCollection`, `keep`, `keepRecursive`, `keys`, `length`, `matches`, `merge`, `mergeRecursive`, `parseCollection`, `parseIdentifier`, `parseKey`, `translate`, `unsetAttributes`, `unsetRecursive`, `value`, `values`, `zip` |
| Database information | `length`, `collectionCount`, `currentDatabase`, `currentUser` |

## Type checks

All these functions share the signature `(mixed $value) : string` and produce the corresponding AQL predicate. They are **boolean expressions** typically inserted into an `aqlFilter()` or `aqlReturn()`.

| Function | AQL output | True if |
|---|---|---|
| `isArray` | `IS_ARRAY(<value>)` | array or array-like object |
| `isBool` | `IS_BOOL(<value>)` | boolean |
| `isDateString` | `IS_DATESTRING(<value>)` | valid ISO 8601 string |
| `isKey` | `IS_KEY(<value>)` | string matching the ArangoDB `_key` grammar |
| `isNull` | `IS_NULL(<value>)` | strict `null` |
| `isNumber` | `IS_NUMBER(<value>)` | integer or float |
| `isObject` | `IS_OBJECT(<value>)` | object (document) |
| `isString` | `IS_STRING(<value>)` | string |

```php
use function oihana\arango\db\functions\isString ;
use function oihana\arango\db\functions\isNull   ;

isString( 'doc.email' ) ;     // "IS_STRING(doc.email)"
isNull  ( 'doc.parent' ) ;    // "IS_NULL(doc.parent)"
```

## Type conversions

| Function | Signature | AQL output |
|---|---|---|
| `toArray` | `(mixed $value)` | `TO_ARRAY(<value>)` |
| `toBool` | `(mixed $value)` | `TO_BOOL(<value>)` |
| `toNumber` | `(mixed $value)` | `TO_NUMBER(<value>)` |
| `toString` | `(mixed $value)` | `TO_STRING(<value>)` |

Explicit type conversions — AQL equivalents of PHP's `(bool)`, `(int)`, `(string)`, `(array)`. Useful to compare fields of different types or normalize heterogeneous data.

```php
use function oihana\arango\db\functions\toNumber ;

toNumber( 'doc.amount' ) ;     // "TO_NUMBER(doc.amount)"
```

## Type inspection

| Function | Signature | AQL output |
|---|---|---|
| `typeName` | `(mixed $value)` | `TYPENAME(<value>)` |

Returns the AQL type name of a value (`'null'`, `'bool'`, `'number'`, `'string'`, `'array'`, `'object'`). Useful for *debug* or to filter dynamically by type.

## Conditional choice

| Function | Signature | AQL output |
|---|---|---|
| `firstDocument` | `(mixed ...$alternatives)` | `FIRST_DOCUMENT(<a>, <b>, ...)` |
| `firstList` | `(mixed ...$alternatives)` | `FIRST_LIST(<a>, <b>, ...)` |
| `notNull` | `(mixed ...$alternatives)` | `NOT_NULL(<a>, <b>, ...)` |

`firstDocument` returns the first alternative that is an object/document (or `null`). `firstList` returns the first alternative that is an array. `notNull` returns the first non-`null` alternative — AQL alternative to the `??` operator found in [`nullish`](aql-operators.md#logical-and-conditional-operators).

```php
use function oihana\arango\db\functions\notNull ;

notNull( 'doc.nickname' , 'doc.firstName' , "'Anonymous'" ) ;
// "NOT_NULL(doc.nickname, doc.firstName, 'Anonymous')"
```

## Documents (root)

| Function | Signature | AQL output |
|---|---|---|
| `document` | `(mixed ...$values)` | `DOCUMENT(<args>)` |
| `checkDocument` | `(mixed $document)` | `CHECK_DOCUMENT(<doc>)` |
| `decodeRev` | `(?string $value)` | `DECODE_REV(<rev>)` |

`document` is one of the most useful AQL functions: it dynamically retrieves a document from its `_id` or `_key`. `checkDocument` validates that an object is a well-formed document (no duplicate keys). `decodeRev` decomposes a `_rev` into `{ date, count }`.

```php
use function oihana\arango\db\functions\document ;

document( "'users/42'"        ) ;     // "DOCUMENT('users/42')"
document( "'users'" , 'doc.userId' ) ; // "DOCUMENT('users', doc.userId)"
```

## Documents (`documents/` subfolder)

The full set of the AQL *document/object functions* (21 helpers, one per `DocumentFunction` constant):

| Function | Signature | AQL output |
|---|---|---|
| `attributes` | `(string $document, ?bool $removeSystemAttrs = null, ?bool $sort = null)` | `ATTRIBUTES(<doc>[, <bool>[, <bool>]])` |
| `count` | `(string $document)` | `COUNT(<doc>)` |
| `entries` | `(string $document)` | `ENTRIES(<doc>)` |
| `has` | `(string $document, string $attributeName)` | `HAS(<doc>, <attr>)` |
| `isSameCollection` | `(string $collectionName, string $documentIdentifier)` | `IS_SAME_COLLECTION("<coll>", <id>)` |
| `keep` | `(string $document, string ...$attributes)` | `KEEP(<doc>, "<attr>", ...)` |
| `keepRecursive` | `(string $document, string ...$attributes)` | `KEEP_RECURSIVE(<doc>, "<attr>", ...)` |
| `keys` | `(string $document, ?bool $removeSystemAttrs = null, ?bool $sort = null)` | `KEYS(<doc>[, <bool>[, <bool>]])` |
| `length` | `(string $document)` | `LENGTH(<doc>)` |
| `matches` | `(string $document, string\|array $examples, ?bool $returnIndex = null)` | `MATCHES(<doc>, <examples>[, <bool>])` |
| `merge` | `(string\|array\|null $documents)` | `MERGE(<docs>)` |
| `mergeRecursive` | `(string\|array\|null $documents)` | `MERGE_RECURSIVE(<docs>)` |
| `parseCollection` | `(string $documentIdentifier)` | `PARSE_COLLECTION(<id>)` |
| `parseIdentifier` | `(string $documentIdentifier)` | `PARSE_IDENTIFIER(<id>)` |
| `parseKey` | `(string $documentIdentifier)` | `PARSE_KEY(<id>)` |
| `translate` | `(mixed $value, mixed $lookupDocument, mixed $defaultValue = null)` | `TRANSLATE(<value>, <lookup>[, <default>])` |
| `unsetAttributes` | `(string $document, string ...$attributes)` | `UNSET(<doc>, "<attr>", ...)` |
| `unsetRecursive` | `(string $document, string ...$attributes)` | `UNSET_RECURSIVE(<doc>, "<attr>", ...)` |
| `value` | `(string $document, array $path)` | `VALUE(<doc>, [<path>])` |
| `values` | `(string $document, ?bool $removeSystemAttrs = null)` | `VALUES(<doc>[, <bool>])` |
| `zip` | `(string\|array $keys, string\|array $values)` | `ZIP(<keys>, <values>)` |

Key reference (`attributes`/`keys`, `values`, `entries`, `count`/`length`), projection (`keep`/`keepRecursive`, `unsetAttributes`/`unsetRecursive`), merging (`merge`/`mergeRecursive`), building (`zip`), lookup (`translate`, `value`), matching (`matches`, `has`), and identifier parsing (`parseCollection`/`parseIdentifier`/`parseKey`, `isSameCollection`).

> **Quoting** — helpers whose AQL argument must be a *string literal* quote it for you: the attribute names of `keep`/`unset*`, the collection name of `isSameCollection`, and PHP arrays passed to `matches`/`zip` (emitted as JSON via `json_encode`). The `document` / identifier arguments stay raw AQL expressions (`doc`, `@bind`, `doc._id`). `unsetAttributes()` is named so because `unset` is a reserved PHP keyword.

```php
use function oihana\arango\db\functions\documents\keep            ;
use function oihana\arango\db\functions\documents\unsetAttributes  ;
use function oihana\arango\db\functions\documents\merge            ;
use function oihana\arango\db\functions\documents\zip             ;

keep( 'doc' , 'name' , 'email' ) ;        // "KEEP(doc,\"name\",\"email\")"
unsetAttributes( 'doc' , '_id' , '_rev' ); // "UNSET(doc,\"_id\",\"_rev\")"
merge( [ 'doc' , '{ active: true }' ] ) ;  // "MERGE(doc,{ active: true })"
zip( [ 'a' , 'b' ] , [ 1 , 2 ] ) ;         // "ZIP([\"a\",\"b\"],[1,2])"
```

## Database information

| Function | Signature | AQL output |
|---|---|---|
| `length` | `(mixed $collection)` | `LENGTH(<collection>)` |
| `collectionCount` | `(mixed $collection)` | `COLLECTION_COUNT(<collection>)` |
| `currentDatabase` | `()` | `CURRENT_DATABASE()` |
| `currentUser` | `()` | `CURRENT_USER()` |

`length` and `collectionCount` return the number of documents in a collection. `LENGTH()` is generally preferred for consistency with the array-side `LENGTH()` (see [arrays](aql-functions-arrays.md)).

`currentDatabase` and `currentUser` are mostly useful for system queries or audits.

> The `length` exposed here (on collection) coexists with the `length` of the `arrays/` sub-folder (on array). Both produce `LENGTH(...)` but are imported from different *namespaces*: `oihana\arango\db\functions\length` (root) vs `oihana\arango\db\functions\arrays\length` (sub-folder).

## Typical composition

Retrieve a related document and expose only some fields if the target exists and is well-formed:

```php
use function oihana\arango\db\operations\aqlReturn ;
use function oihana\arango\db\helpers\aqlDocument  ;
use function oihana\arango\db\functions\document        ;
use function oihana\arango\db\functions\isObject        ;
use function oihana\arango\db\functions\notNull         ;
use function oihana\arango\db\functions\documents\value ;

aqlReturn( aqlDocument
([
    'self'    => 'doc' ,
    'owner'   => document( "'users'" , 'doc.ownerId' ) ,
    'parent'  => notNull( document( "'users'" , 'doc.parentId' ) , 'null' ) ,
    'nestedX' => value( 'doc' , [ "'meta'" , "'x'" ] ) ,
])) ;
```

## See also

- [Building an AQL query step by step](aql-building-queries.md).
- [Operators `db/operators/`](aql-operators.md) — combine `IS_*` predicates with `AND` / `OR`.
- [AQL helpers `db/helpers/`](../db/helpers.md) — `aqlDocument` to compose a return document.
- [Official AQL documentation — Type check & cast](https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/).
- [Official AQL documentation — Document functions](https://docs.arangodb.com/stable/aql/functions/document/).
- [Official AQL documentation — Miscellaneous](https://docs.arangodb.com/stable/aql/functions/miscellaneous/).
