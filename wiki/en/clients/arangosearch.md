# ArangoSearch

ArangoSearch is the full-text search engine built into ArangoDB. Two building blocks:

- An **Analyzer** describes how text is tokenized and normalized — lowercasing, stemming, accent folding, language-aware splitting.
- A **View** is a searchable inverted index that links one or more collections to one or more analyzers and exposes them through the AQL `SEARCH ... IN view` clause.

This page covers both surfaces on the client side. For full-text on a **single** collection you can also use an [`InvertedIndex`](indexes.md#invertedindex--modern-full-text-310) — Views start paying off as soon as you need to search across several collections or rank results.

## Analyzers

### Create an analyzer

```php
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;

$db->createAnalyzer(
    'text_fr' ,
    new TextAnalyzer(
        locale   : 'fr.utf-8' ,
        case     : 'lower' ,
        accent   : false ,        // fold accents
        stemming : true ,
    ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
) ;
```

`createAnalyzer()` is the shortcut for `$db->analyzer( $name )->create( ... )`.

### Analyzer classes

All four are readonly value objects implementing `AnalyzerOptions::toArray()`:

| Class | What it does | Constructor parameters |
|---|---|---|
| `IdentityAnalyzer` | Pass-through — no transformation. Used as the default if no analyzer is configured on a link. | (none) |
| `NormAnalyzer` | Locale-aware case folding + optional accent removal. No tokenization. | `locale`, `?case`, `?accent` |
| `StemAnalyzer` | Locale-aware stemming. Input must already be tokenized. | `locale` |
| `TextAnalyzer` | Full-text tokenizer + optional stemming, stopwords, accent folding, edge n-grams. **The workhorse.** | `locale`, `?case`, `?accent`, `?stemming`, `?stopwords`, `?stopwordsPath`, `?edgeNgram` |

### Analyzer features

Picked per analyzer at creation time, decide what extra index data is kept:

| Feature | Required by |
|---|---|
| `FREQUENCY` | `BM25()` and `TFIDF()` scoring |
| `NORM` | `BM25()` length normalization |
| `POSITION` | `PHRASE()` matching |
| `OFFSET` | Result highlighting (implies `POSITION`) |

Cost more space and write CPU — only enable what your queries need.

### Lifecycle methods

```php
$analyzer = $db->analyzer( 'text_fr' ) ;       // factory, no HTTP

$analyzer->exists() ;                          // bool
$analyzer->get() ;                             // raw description: type, properties, features
$analyzer->drop( force: false ) ;              // pass force: true to drop even if in use by a view
```

`$db->analyzers()` returns `Analyzer` handles for every analyzer; `$db->listAnalyzers()` returns the raw descriptions. Both include the built-in analyzers (`identity`, `text_en`, etc.).

## Views

### Create a view

A view is described by a `links` map: collection name → `ArangoSearchLink` config.

```php
use oihana\arango\clients\view\ArangoSearchLink ;

$db->createView(
    'articles_search' ,
    [
        'articles' => new ArangoSearchLink(
            analyzers        : [ 'text_fr' , 'identity' ] ,
            fields           : [
                'title' => new ArangoSearchLink( analyzers: [ 'text_fr' ] ) ,
                'body'  => new ArangoSearchLink( analyzers: [ 'text_fr' ] ) ,
                'tags'  => new ArangoSearchLink( analyzers: [ 'identity' ] ) ,
            ] ,
            includeAllFields : false ,
        ) ,
    ] ,
    [
        'consolidationIntervalMsec' => 1_000 ,
        'commitIntervalMsec'        => 1_000 ,
    ] ,
) ;
```

### `ArangoSearchLink` parameters

```php
new ArangoSearchLink(
    analyzers          : null ,  // list of analyzer names — defaults to [ 'identity' ]
    fields             : null ,  // recursive: map of field → ArangoSearchLink
    includeAllFields   : null ,  // index every attribute (overrides `fields`)
    trackListPositions : null ,  // remember ordinal positions in array fields
    storeValues        : null ,  // 'none' (default) or 'id' (enables covering queries)
    inBackground       : null ,  // top-level only — build without blocking writes
) ;
```

The `fields` recursion lets you bind a different set of analyzers to each path. A field's `analyzers` overrides the parent link's.

### Lifecycle methods

```php
$view = $db->view( 'articles_search' ) ;   // factory, no HTTP

$view->exists() ;          // bool
$view->get() ;             // brief: name, type, id
$view->properties() ;      // full config including links + consolidation settings
$view->drop() ;            // collections untouched
```

### Update properties

`updateProperties()` and `replaceProperties()` mirror PATCH and PUT semantics:

```php
// Additive merge — useful for adding a new collection link.
$view->updateProperties([
    'links' => [
        'comments' => ( new ArangoSearchLink( analyzers: [ 'text_fr' ] ) )->toArray() ,
    ] ,
]) ;

// Full replace — absent fields revert to defaults.
$view->replaceProperties([
    'links' => [
        'articles' => ( new ArangoSearchLink( analyzers: [ 'text_fr' ] ) )->toArray() ,
    ] ,
]) ;
```

## Searching from AQL

A view is consumed via the `SEARCH ... IN view` clause:

```php
use function oihana\arango\clients\aql\helpers\aql ;

$cursor = $db->query( aql(
    'FOR doc IN articles_search ' .
    'SEARCH ANALYZER(doc.title == ?, "text_fr") ' .
    'SORT BM25(doc) DESC ' .
    'LIMIT 10 ' .
    'RETURN doc' ,
    'arangodb' ,
) ) ;
```

Operators worth knowing:

| AQL function | What it does | Requires feature |
|---|---|---|
| `ANALYZER( cond , 'name' )` | Bind an analyzer to a sub-condition | — |
| `PHRASE( field , 'words' , 'analyzer' )` | Phrase match | `POSITION` |
| `STARTS_WITH( field , 'prefix' )` | Prefix match (works with edge-n-gram text analyzer) | — |
| `TOKENS( 'input' , 'analyzer' )` | Tokenize an input string with an analyzer | — |
| `BM25( doc , k , b )` | Relevance score (Okapi BM25) | `FREQUENCY`, `NORM` (optional) |
| `TFIDF( doc , false )` | Term-frequency / inverse-document-frequency score | `FREQUENCY` |

See the [official ArangoSearch reference](https://docs.arangodb.com/stable/index-and-search/arangosearch/) for the complete operator catalog.

## A complete recipe

```php
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;
use oihana\arango\clients\view\ArangoSearchLink ;
use function oihana\arango\clients\aql\helpers\aql ;

// 1. Analyzer for French text.
$db->createAnalyzer(
    'text_fr' ,
    new TextAnalyzer( locale: 'fr.utf-8' , case: 'lower' , accent: false , stemming: true ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::NORM , AnalyzerFeature::POSITION ] ,
) ;

// 2. View linking the 'articles' collection.
$db->createView(
    'articles_search' ,
    [
        'articles' => new ArangoSearchLink(
            fields : [
                'title' => new ArangoSearchLink( analyzers: [ 'text_fr' ] ) ,
                'body'  => new ArangoSearchLink( analyzers: [ 'text_fr' ] ) ,
            ] ,
        ) ,
    ] ,
) ;

// 3. Search with BM25 ranking.
$hits = $db->query( aql(
    'FOR doc IN articles_search ' .
    'SEARCH ANALYZER(doc.title IN TOKENS(?, "text_fr") ' .
    '           OR doc.body  IN TOKENS(?, "text_fr"), "text_fr") ' .
    'SORT BM25(doc) DESC ' .
    'LIMIT 20 ' .
    'RETURN { _key: doc._key, title: doc.title, score: BM25(doc) }' ,
    'base de données graphe' ,
    'base de données graphe' ,
) )->all() ;
```

## Where next

- [AQL queries and Cursors](aql.md) — write the `SEARCH` queries that consume your views.
- [Indexes](indexes.md) — for single-collection full-text via `InvertedIndex`.
- [HTTP client overview](README.md) — architecture and configuration.
