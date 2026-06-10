# String functions `db/functions/strings/`

The [`src/oihana/arango/db/functions/strings/`](../../../src/oihana/arango/db/functions/strings/) sub-folder groups **37 functions** that match the native AQL *string functions*. Each PHP function returns the string `AQL_FUNCTION(args)` ready to be inserted in a predicate or projection.

> Important distinction: the functions on this page produce AQL `LOWER(doc.name)` from the PHP code `lower('doc.name')`. They have **no relationship** with the `alt` transformations exposed on the HTTP URL side (`?filter={"alt":"lower"}`) documented in [`filter.md`](../db/filter.md) — these are two parallel worlds despite similar-sounding names.

## Summary

| Category | Functions |
|---|---|
| Case | `lower`, `upper` |
| Trimming | `trim`, `ltrim`, `rtrim` |
| Extraction and concatenation | `subString`, `left`, `right`, `concat`, `concatSeparator`, `split`, `tokens` |
| Search | `contains`, `startsWith`, `like`, `findFirst`, `findLast` |
| Length | `charLength` |
| Hashes and fingerprints | `md5`, `sha1`, `sha256`, `sha512`, `crc32`, `fnv64`, `soundex`, `levenshtein` |
| Encoding and conversion | `toBase64`, `toHex`, `encodeURIComponent`, `toChar`, `jsonParse`, `jsonStringify` |
| Generation | `uuid`, `randomToken` |
| IPv4 | `ipv4FromNumber`, `ipv4ToNumber`, `isIPV4` |

## Case

| Function | Signature | AQL output |
|---|---|---|
| `lower` | `(string $value)` | `LOWER(<value>)` |
| `upper` | `(string $value)` | `UPPER(<value>)` |

```php
use function oihana\arango\db\functions\strings\lower ;
use function oihana\arango\db\functions\strings\upper ;

lower( 'doc.email' ) ;   // "LOWER(doc.email)"
upper( 'doc.code'  ) ;   // "UPPER(doc.code)"
```

## Trimming

| Function | Signature | AQL output |
|---|---|---|
| `trim` | `(string $value, string\|int\|null $charsOrType = null)` | `TRIM(<value>[, <type>])` |
| `ltrim` | `(string $value, ?string $chars = null)` | `LTRIM(<value>[, <chars>])` |
| `rtrim` | `(string $value, ?string $chars = null)` | `RTRIM(<value>[, <chars>])` |

For `trim`, the second parameter is either an integer (`0` = both sides, `1` = left, `2` = right) or a string of characters to remove.

```php
trim ( 'doc.name'        ) ;        // "TRIM(doc.name)"
trim ( 'doc.path' , '/'  ) ;        // "TRIM(doc.path, '/')"
ltrim( 'doc.code' , 'X'  ) ;        // "LTRIM(doc.code, 'X')"
```

## Extraction and concatenation

| Function | Signature | AQL output |
|---|---|---|
| `subString` | `(string $value, int $offset, ?int $length = null)` | `SUBSTRING(<value>, <offset>[, <length>])` |
| `left` | `(string $value, int $length)` | `LEFT(<value>, <length>)` |
| `right` | `(string $value, int $length)` | `RIGHT(<value>, <length>)` |
| `concat` | `(array\|string\|null $arguments)` | `CONCAT(<args>)` |
| `concatSeparator` | `(string $separator, array\|string\|null $arguments = null)` | `CONCAT_SEPARATOR(<separator>, <args>)` |
| `split` | `(string $value, string $separator, ?int $limit = null)` | `SPLIT(<value>, <separator>[, <limit>])` |
| `tokens` | `(string $init, string $analyzer)` | `TOKENS(<text>, <analyzer>)` |

```php
subString( 'doc.code' , 0 , 3 ) ;          // "SUBSTRING(doc.code, 0, 3)"
concat( [ 'doc.firstName' , "' '" , 'doc.lastName' ] ) ;
// "CONCAT(doc.firstName, ' ', doc.lastName)"
concatSeparator( "'-'" , [ 'doc.year' , 'doc.month' , 'doc.day' ] ) ;
// "CONCAT_SEPARATOR('-', doc.year, doc.month, doc.day)"
```

`tokens` is used in the ArangoSearch context: it tokenizes a string through an analyzer declared server-side (`text_en`, `text_fr`, ...).

## Search

| Function | Signature | AQL output |
|---|---|---|
| `contains` | `(string $text, string $search, bool $returnIndex = false)` | `CONTAINS(<text>, <search>[, <returnIndex>])` |
| `startsWith` | `(string $value, string\|array $prefix, ?int $minMatchCount = null)` | `STARTS_WITH(<value>, <prefix>[, <count>])` |
| `like` | `(string $text, string $search, bool $caseSensitive = false)` | `LIKE(<text>, <search>[, <caseSensitive>])` |
| `findFirst` | `(string $value, string $search, ?int $start, ?int $end)` | `FIND_FIRST(<value>, <search>[, <start>[, <end>]])` |
| `findLast` | `(string $value, string $search, ?int $start, ?int $end)` | `FIND_LAST(<value>, <search>[, <start>[, <end>]])` |

```php
contains  ( 'doc.bio'   , "'php'"   , true ) ;     // "CONTAINS(doc.bio, 'php', true)"
startsWith( 'doc.title' , "'Mr'"           ) ;     // "STARTS_WITH(doc.title, 'Mr')"
startsWith( 'doc.title' , [ 'Mr' , 'Dr' ] , 1 ) ;  // "STARTS_WITH(doc.title,[\"Mr\",\"Dr\"],1)" (SEARCH form)
like      ( 'doc.name'  , "'%john%'"       ) ;     // "LIKE(doc.name, '%john%')"
```

## Length

| Function | Signature | AQL output |
|---|---|---|
| `charLength` | `(string $expression)` | `CHAR_LENGTH(<value>)` |

For array length, see [`length`](aql-functions-arrays.md) in the array functions.

## Hashes and fingerprints

| Function | Signature | AQL output |
|---|---|---|
| `md5` | `(string $value)` | `MD5(<value>)` |
| `sha1` | `(string $value)` | `SHA1(<value>)` |
| `sha256` | `(string $value)` | `SHA256(<value>)` |
| `sha512` | `(string $value)` | `SHA512(<value>)` |
| `crc32` | `(string $value)` | `CRC32(<value>)` (hexadecimal) |
| `fnv64` | `(string $value)` | `FNV64(<value>)` (hexadecimal) |
| `soundex` | `(string $value)` | `SOUNDEX(<value>)` (English phonetic fingerprint) |
| `levenshtein` | `(string $value1, string $value2)` | `LEVENSHTEIN_DISTANCE(<a>, <b>)` |

Cryptographic functions (`md5`, `sha*`) are to be used only for cache or deduplication fingerprints — never to store passwords.

```php
md5      ( 'doc.email'                  ) ;        // "MD5(doc.email)"
soundex  ( 'doc.lastName'               ) ;        // "SOUNDEX(doc.lastName)"
levenshtein( 'doc.name' , "'francois'"  ) ;        // "LEVENSHTEIN_DISTANCE(doc.name, 'francois')"
```

## Encoding and conversion

| Function | Signature | AQL output |
|---|---|---|
| `toBase64` | `(string $value)` | `TO_BASE64(<value>)` |
| `toHex` | `(string $value)` | `TO_HEX(<value>)` |
| `encodeURIComponent` | `(string $value)` | `ENCODE_URI_COMPONENT(<value>)` |
| `toChar` | `(int $codepoint)` | `TO_CHAR(<codepoint>)` |
| `jsonParse` | `(string $text)` | `JSON_PARSE(<text>)` |
| `jsonStringify` | `(mixed $value)` | `JSON_STRINGIFY(<value>)` |

`jsonParse` and `jsonStringify` let you store serialized JSON and reparse it server-side (useful for *blob* fields you don't want to index).

## Generation

| Function | Signature | AQL output |
|---|---|---|
| `uuid` | `()` | `UUID()` |
| `randomToken` | `(int $length)` | `RANDOM_TOKEN(<length>)` |

`uuid` produces a v4 UUID. `randomToken` generates a random string of the requested length (useful for API tokens to sign).

## IPv4

| Function | Signature | AQL output |
|---|---|---|
| `ipv4FromNumber` | `(string $value)` | `IPV4_FROM_NUMBER(<value>)` |
| `ipv4ToNumber` | `(string $value)` | `IPV4_TO_NUMBER(<value>)` |
| `isIPV4` | `(string $value)` | `IS_IPV4(<value>)` |

Conversions between text representation (`'192.168.1.1'`) and 32-bit integer, plus a validation predicate. Useful to store IPs compactly while keeping the ability to filter on them.

## Typical composition

Functions nest freely, since they all return an AQL string. Example of a normalized comparison:

```php
use function oihana\arango\db\operators\equal           ;
use function oihana\arango\db\operations\aqlFilter      ;
use function oihana\arango\db\functions\strings\lower   ;
use function oihana\arango\db\functions\strings\trim    ;
use function oihana\arango\db\binds\aqlBind             ;

aqlFilter
(
    equal
    (
        lower( trim( 'doc.email' ) ) ,                                     // LOWER(TRIM(doc.email))
        aqlBind( strtolower( $userInput ) , $binds , 'email' )             // @email
    )
) ;
// "FILTER LOWER(TRIM(doc.email)) == @email"
```

## See also

- [Building an AQL query step by step](aql-building-queries.md).
- [Operators `db/operators/`](aql-operators.md) — the comparators where these functions are inserted.
- [Bind variables `db/binds/`](../db/binds.md) — for comparison values.
- [Official AQL documentation — String functions](https://docs.arangodb.com/stable/aql/functions/string/).
