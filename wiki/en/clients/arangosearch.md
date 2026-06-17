# ArangoSearch

ArangoSearch is the full-text search engine built into ArangoDB. Two building blocks:

- An **Analyzer** describes how text is tokenized and normalized — lowercasing, stemming, accent folding, language-aware splitting.
- A **View** is a searchable inverted index that links one or more collections to one or more analyzers and exposes them through the AQL `SEARCH ... IN view` clause.

New to the concepts? Start with the [Understanding ArangoSearch](../getting-started/arangosearch.md) primer. This page covers both surfaces on the client side. For full-text on a **single** collection you can also use an [`InvertedIndex`](indexes.md#invertedindex--modern-full-text-310) — Views start paying off as soon as you need to search across several collections or rank results.

## Analyzers

> **Dedicated page:** the analyzer types, what each one produces, the features
> and how to build a custom analyzer are explained in detail in
> **[Analyzers](../db/analyzers.md)**. This section only covers the client-side
> lifecycle API.

Create an analyzer (shortcut for `$db->analyzer( $name )->create( ... )`):

```php
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;

$db->createAnalyzer(
    'text_fr_custom' ,
    new TextAnalyzer( locale: 'fr.utf-8' , case: 'lower' , accent: false , stemming: true ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
) ;
```

The four option classes (`IdentityAnalyzer`, `NormAnalyzer`, `StemAnalyzer`,
`TextAnalyzer`) and their features are described in [Analyzers](../db/analyzers.md).

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

### `search-alias` views

The second view type, `search-alias`, does **not** own its index. It is a thin alias
over one **`inverted` index per collection** — each collection owns and manages its own
index (shareable, independent lifecycle), and the view simply aggregates them. This is the
natural substrate for a **federated, multi-collection search** (one search bar over
`customers`, `products`, …).

First declare an inverted index on each collection, then create the alias:

```php
use oihana\arango\clients\collection\indexes\InvertedIndex ;
use oihana\arango\db\options\views\SearchAliasView ;

// 1. one inverted index per collection (a first-class index type)
$inv = new InvertedIndex( fields: [ 'name' , 'email' ] , name: 'inv_search' , analyzer: 'text_fr' ) ;
$db->collection( 'customers' )->createIndex( $inv ) ;
$db->collection( 'products'  )->createIndex( $inv ) ;

// 2. a search-alias view aggregating them
$view = new SearchAliasView( 'global_search' ,
[
    'customers' => 'inv_search' ,   // collection => inverted-index name
    'products'  => 'inv_search' ,
] ) ;
$db->view( 'global_search' )->createSearchAlias( $view->getIndexes() ) ;

// 3. a single federated SEARCH spans every aliased collection
$db->query( 'FOR d IN global_search SEARCH ANALYZER(d.name IN TOKENS(@q, "text_fr"), "text_fr") SORT BM25(d) DESC RETURN d' , [ 'q' => 'dupont' ] ) ;
```

`SearchAliasView::getIndexes()` accepts either the `collection => index` map shown above or
an explicit `[ [ 'collection' => 'customers', 'index' => 'inv_search' ], … ]` list. For a
managed, declarative setup, declare the views in the `searchAliasViews` registry
(`ArangoSearchAliasViewsTrait`) — the database-level counterpart of the analyzer registry.

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

- [Understanding ArangoSearch](../getting-started/arangosearch.md) — the concepts primer (Analyzers, Views, `SEARCH`, scoring) and the library layer-by-layer map.
- [View search (ArangoSearch)](../db/search-views.md) — declare a View on a `Documents` model: relevance-ranked `?search=` with auto-provisioning.
- [ArangoSearch functions](../aql/aql-functions-search.md) — the `SEARCH`-expression helpers (`phrase`, `boost`, `bm25`, …).
- [AQL queries and Cursors](aql.md) — write the `SEARCH` queries that consume your views.
- [Indexes](indexes.md) — for single-collection full-text via `InvertedIndex`.
- [HTTP client overview](README.md) — architecture and configuration.
