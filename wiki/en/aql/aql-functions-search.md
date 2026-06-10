# ArangoSearch functions `db/functions/search/`

The [`src/oihana/arango/db/functions/search/`](../../../src/oihana/arango/db/functions/search/) sub-folder groups the **11 helpers** that match the native AQL *ArangoSearch functions* — search-expression context, index-accelerated filtering, and relevance scoring.

> **What is ArangoSearch?** ArangoDB's built-in search engine. An **Analyzer** turns text into normalized tokens (`"Quick Foxes"` → `["quick", "fox"]`), a **View** indexes one or more collections field by field through those Analyzers, and the **`SEARCH` operation** queries the View through its inverted index — unlike `FILTER`, which post-processes. **Scorers** (`BM25()`, `TFIDF()`) then rank the matches by relevance. See the [Understanding ArangoSearch](../getting-started/arangosearch.md) primer for the full picture, [ArangoSearch clients](../clients/arangosearch.md) for creating Views and Analyzers, and [`aqlSearch()`](aql-operations.md) for the operation itself.

These expressions are only valid **inside a `SEARCH` operation** against a View (or a `FILTER` backed by an inverted index) — most of them throw when evaluated elsewhere.

## Summary

| Category | Functions |
|---|---|
| Context | `analyzer`, `boost` |
| Filtering | `phrase`, `levenshteinMatch`, `ngramMatch`, `minhashMatch`, `minMatch`, `exists`, `inRange` |
| Scoring | `bm25`, `tfidf` |

The `STARTS_WITH`, `TOKENS` and `LIKE` functions also work in `SEARCH` expressions but live with the [string functions](aql-functions-strings.md) — note that [`startsWith()`](aql-functions-strings.md) supports the ArangoSearch array-of-prefixes form (`startsWith('doc.text', ['lor','ips'], 1)`). The `OFFSET_INFO` highlighting function is exposed as a `SearchFunction` constant only (helper deferred).

## Reference

| Function | Signature | AQL output |
|---|---|---|
| `analyzer` | `(string $expr, string $analyzer)` | `ANALYZER(<expr>, "<analyzer>")` |
| `boost` | `(string $expr, float\|int $boost)` | `BOOST(<expr>, <boost>)` |
| `phrase` | `(string $path, string\|array $phrase, ?string $analyzer = null)` | `PHRASE(<path>, "<phrase>"[, "<analyzer>"])` |
| `levenshteinMatch` | `(string $path, string $target, int $distance, ?bool $transpositions = null, ?int $maxTerms = null, ?string $prefix = null)` | `LEVENSHTEIN_MATCH(<path>, "<target>", <distance>[, …])` |
| `ngramMatch` | `(string $path, string $target, string $analyzer, ?float $threshold = null)` | `NGRAM_MATCH(<path>, "<target>"[, <threshold>], "<analyzer>")` |
| `minhashMatch` | `(string $path, string $target, string $analyzer, ?float $threshold = null)` | `MINHASH_MATCH(<path>, "<target>"[, <threshold>], "<analyzer>")` |
| `minMatch` | `(array $expressions, int $minMatchCount)` | `MIN_MATCH(<expr1>, …, <exprN>, <count>)` |
| `exists` | `(string $path, ?string $type = null, ?string $analyzer = null)` | `EXISTS(<path>[, "<type>"[, "<analyzer>"]])` |
| `inRange` | `(string $path, mixed $low, mixed $high, bool $includeLow, bool $includeHigh)` | `IN_RANGE(<path>, <low>, <high>, <bool>, <bool>)` |
| `bm25` | `(string $doc, ?float $k = null, ?float $b = null)` | `BM25(<doc>[, <k>[, <b>]])` |
| `tfidf` | `(string $doc, ?bool $normalize = null)` | `TFIDF(<doc>[, <normalize>])` |

## Conventions

- **Auto-quoting** — arguments that must be AQL string literals (Analyzer names, search targets, `exists` types, the `phrase` content) are emitted with `json_encode`: plain strings are quoted, double quotes and non-ASCII characters become valid JSON-style escapes. Attribute paths, search expressions and the `doc` variable are kept **raw**; PHP booleans become `true`/`false`.
- **`phrase()` array form** — pass an array to mirror the official AQL array syntax one-to-one: string tokens, integer `skipTokens` wildcards, and associative arrays as object tokens (`['STARTS_WITH' => ['ips']]` → `{"STARTS_WITH":["ips"]}`).
- **Argument order notice** — in AQL, `NGRAM_MATCH`/`MINHASH_MATCH` place their *optional* `threshold` before the *mandatory* `analyzer`; PHP forbids that, so the helpers take the analyzer third, the threshold last, and re-order the emitted AQL.
- **Default filling** — AQL arguments are positional, so when a later optional argument is given, the helpers fill the earlier omitted ones with the official server defaults: `levenshteinMatch(…, prefix: 'qui')` emits `transpositions = true` and `maxTerms = 64`; `bm25('doc', b: 0.5)` emits `k = 1.2`.
- **`exists()` analyzer shortcut** — `exists('doc.text', analyzer: 'text_en')` fills the `"analyzer"` type literal by itself. With `arangosearch` Views, `EXISTS()` only matches if the link sets `storeValues: "id"`.

## Examples

A relevance-ranked search — the phrase weighs 3× more in the name than a fuzzy match in the description:

```php
use function oihana\arango\db\functions\search\analyzer ;
use function oihana\arango\db\functions\search\bm25 ;
use function oihana\arango\db\functions\search\boost ;
use function oihana\arango\db\functions\search\levenshteinMatch ;
use function oihana\arango\db\functions\search\phrase ;

$search = analyzer
(
    boost( phrase( 'doc.name' , 'scierie' ) , 3 )
    . ' OR ' .
    levenshteinMatch( 'doc.description' , 'scierie' , 1 ) ,
    'text_fr'
) ;

$aql = 'FOR doc IN placesView SEARCH ' . $search
     . ' SORT ' . bm25( 'doc' ) . ' DESC LIMIT 20 RETURN doc' ;
// FOR doc IN placesView
//   SEARCH ANALYZER(BOOST(PHRASE(doc.name,"scierie"),3) OR LEVENSHTEIN_MATCH(doc.description,"scierie",1), "text_fr")
//   SORT BM25(doc) DESC LIMIT 20 RETURN doc
```

A proximity phrase ("quick … fox" with one wildcard token between) and an object token:

```php
use function oihana\arango\db\functions\search\phrase ;

phrase( 'doc.text' , [ 'quick' , 1 , 'fox' ] , 'text_en' ) ;
// 'PHRASE(doc.text,["quick",1,"fox"],"text_en")'

phrase( 'doc.text' , [ 'lorem' , [ 'STARTS_WITH' => [ 'ips' ] ] ] , 'text_en' ) ;
// 'PHRASE(doc.text,["lorem",{"STARTS_WITH":["ips"]}],"text_en")'
```

Existence and range checks, combined ("has a text, and 3 ≤ value ≤ 5"):

```php
use function oihana\arango\db\functions\search\exists ;
use function oihana\arango\db\functions\search\inRange ;

exists( 'doc.text' ) . ' AND ' . inRange( 'doc.value' , 3 , 5 , true , true ) ;
// 'EXISTS(doc.text) AND IN_RANGE(doc.value,3,5,true,true)'
```

> `ngramMatch()` and `minhashMatch()` require custom `ngram` / `minhash` Analyzers — see [Analyzers](../clients/arangosearch.md) to create them.

## See also

- [String functions `db/functions/strings/`](aql-functions-strings.md) — `startsWith` (array form), `tokens`, `like`.
- [AQL operations](aql-operations.md) — the `aqlSearch()` operation and the `aqlScoredSearch()` query builder.
- [ArangoSearch clients](../clients/arangosearch.md) — Views, links and Analyzers.
- [Building an AQL query step by step](aql-building-queries.md).
- [Official AQL documentation — ArangoSearch functions](https://docs.arangodb.com/stable/aql/functions/arangosearch/).
