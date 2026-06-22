# Analyzers

An **Analyzer** is ArangoSearch's *text-preparation recipe*. Before storing text
in a search index — and before comparing what you type — the engine runs the
text through this recipe: it tokenizes, lower-cases, strips accents, reduces
words to their root, and so on. Because the **same** recipe applies at indexing
*and* at query time, both sides always meet on the same ground.

```
"Les Scieries de l'Évre !"   --text_fr-->   [ scieri , evre ]
```

> **Analogy.** `text_fr` is a librarian who shelves by topic and understands
> synonyms: ask for "scierie" and it finds "Scieries". `identity` is a locker
> with an exact label: you find the box **only** if you give the label word for
> word. You pick the recipe based on what the field holds — prose, or a code.

> **An Analyzer is fixed at indexing time.** Overriding it at query time alone is
> pointless (you'd be searching English-stemmed tokens in a French-stemmed
> index). What you change is the *field* being searched, and the right Analyzer
> follows the field — see [Per-field Analyzer](search/per-field-options.md#per-field-analyzer).

## Contents

- [Built-in analyzers](#built-in-analyzers)
- [The five types you can build](#the-five-types-you-can-build)
- [Features — what they unlock](#features--what-they-unlock)
- [Creating an analyzer the right way](#creating-an-analyzer-the-right-way)
- [Lifecycle — the `arango:analyzers` command](#lifecycle--the-arangoanalyzers-command)
- [Wiring an analyzer to a model / View](#wiring-an-analyzer-to-a-model--view)
- [Current limitations](#current-limitations)
- [See also](#see-also)

## Built-in analyzers

ArangoDB ships analyzers that are **always present** — nothing to create, you
reference them by name. They are catalogued in the
[`BuiltinAnalyzer`](../../../src/oihana/arango/clients/analyzer/enums/BuiltinAnalyzer.php)
enum to avoid magic strings:

| Name | Recipe | What for |
|---|---|---|
| `identity` | no transformation, the text as-is | codes, references, identifiers — **exact** match |
| `text_de`, `text_en`, `text_es`, `text_fi`, `text_fr`, `text_it`, `text_nl`, `text_no`, `text_pt`, `text_ru`, `text_sv`, `text_zh` | tokenization + lower-casing + accent folding + stemming, per language | names, descriptions, prose in that language |

```php
use oihana\arango\clients\analyzer\enums\BuiltinAnalyzer ;

Search::ANALYZER => BuiltinAnalyzer::TEXT_FR ,   // 'text_fr'
```

`identity` is the **default** analyzer: a field that declares nothing is indexed
as-is. That is exactly what you want for a `code` field.

## The five types you can build

When the built-ins are not enough (fine accent tuning, custom stopwords,
prefix or substring search…), you build your own analyzer. The library exposes
five "recipe" classes — `readonly` value objects implementing
[`AnalyzerOptions`](../../../src/oihana/arango/clients/analyzer/AnalyzerOptions.php).
This is the **complete set** of what is buildable today (see
[Current limitations](#current-limitations)).

| Class | What it does | Parameters |
|---|---|---|
| [`IdentityAnalyzer`](../../../src/oihana/arango/clients/analyzer/IdentityAnalyzer.php) | as-is, no transformation | (none) |
| [`NormAnalyzer`](../../../src/oihana/arango/clients/analyzer/NormAnalyzer.php) | lower / upper case + accents — **without** tokenizing | `locale`, `case`, `accent` |
| [`StemAnalyzer`](../../../src/oihana/arango/clients/analyzer/StemAnalyzer.php) | stemming (one word → its root); single-word input | `locale` |
| [`TextAnalyzer`](../../../src/oihana/arango/clients/analyzer/TextAnalyzer.php) | the workhorse: tokenizes + lower-cases + accents + stemming + stopwords + prefix n-grams | `locale`, `case`, `accent`, `stemming`, `stopwords`, `stopwordsPath`, `edgeNgram` |
| [`NgramAnalyzer`](../../../src/oihana/arango/clients/analyzer/NgramAnalyzer.php) | splits into substrings (n-grams) from `min` to `max` characters — the building block of **autocomplete** / substring search | `min`, `max`, `preserveOriginal`, `startMarker`, `endMarker`, `streamType` |

`locale` is a BCP 47 / ICU tag (`'fr'`, `'en'`, `'fr.utf-8'`). `case` takes its
values from the [`CaseFolding`](../../../src/oihana/arango/clients/analyzer/enums/CaseFolding.php)
enum (`lower` / `upper` / `none`). A `null` (omitted) parameter lets the server
apply its own default.

### `IdentityAnalyzer` — the exact locker

```php
use oihana\arango\clients\analyzer\IdentityAnalyzer ;

$db->createAnalyzer( 'identity_raw' , new IdentityAnalyzer() ) ;
```

```
"REF-2024"   -->   [ REF-2024 ]      // a single, untouched token
```

> In practice you almost never need to create it: the built-in `identity`
> already does this, and it is the default.

### `NormAnalyzer` — normalize without tokenizing

```php
use oihana\arango\clients\analyzer\NormAnalyzer ;
use oihana\arango\clients\analyzer\enums\CaseFolding ;

$db->createAnalyzer( 'norm_fr' , new NormAnalyzer( locale: 'fr' , case: CaseFolding::LOWER , accent: false ) ) ;
```

```
"Évre"   -->   [ evre ]      // lower-cased + accent folded, NOT tokenized
```

Ideal for case/accent-insensitive sorting or grouping, or to match a short label
without splitting it into words.

### `StemAnalyzer` — reduce to the root

```php
use oihana\arango\clients\analyzer\StemAnalyzer ;

$db->createAnalyzer( 'stem_en' , new StemAnalyzer( locale: 'en' ) ) ;
```

```
"running"   -->   [ run ]      // already-tokenized input (a single word)
```

`StemAnalyzer` expects a single word — to stem a whole sentence, use
`TextAnalyzer` (which tokenizes *then* stems).

### `TextAnalyzer` — full linguistic search

```php
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;
use oihana\arango\clients\analyzer\enums\CaseFolding ;

$db->createAnalyzer
(
    'text_fr_custom' ,
    new TextAnalyzer
    (
        locale    : 'fr.utf-8' ,
        case      : CaseFolding::LOWER ,
        accent    : false ,                 // fold accents
        stemming  : true ,
        stopwords : [ 'le' , 'la' , 'les' , 'de' ] ,
        edgeNgram : [ 'min' => 2 , 'max' => 5 , 'preserveOriginal' => true ] ,
    ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
) ;
```

```
"Les Scieries de l'Évre"   -->   [ sc , sci , scie , scier , scieri , ev , evr , evre ]
```

Stopwords (`les`, `de`, `l'`) are dropped, the rest is lower-cased,
accent-folded, stemmed, then the `edgeNgram` option emits the **prefixes** of
each token (2 to 5 letters) — this is what enables type-as-you-go search (`scie`
finds `scieri`). `preserveOriginal` also keeps the whole token.

### `NgramAnalyzer` — autocomplete / substrings

```php
use oihana\arango\clients\analyzer\NgramAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;

$db->createAnalyzer
(
    'autocomplete' ,
    new NgramAnalyzer
    (
        min              : 2 ,        // minimum fragment length
        max              : 5 ,        // maximum length
        preserveOriginal : true ,     // also keep the whole word
        streamType       : 'utf8' ,   // 'utf8' (per character) or 'binary' (per byte, default)
    ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
) ;
```

```
"atelier"   -->   [ at , ate , atel , ateli , te , tel , teli , telie , ... , atelier ]
```

Unlike the `edgeNgram` option of `TextAnalyzer` — which only emits **prefixes** —
the `ngram` analyzer emits substrings **at every position**. Typing `tel` then
finds `a`**`tel`**`ier`, not only what starts with `tel`. It is the tool for
autocomplete and partial search. You typically **combine** it with a `text`
analyzer on the **same** field (multiple analyzers per field), so the field
serves both whole-word search and autocomplete.

> `ngram` does not lower-case or fold accents: for case-insensitive autocomplete,
> index already-normalized data (or, later, compose with a `pipeline` analyzer).
> The `startMarker` / `endMarker` markers let you tell a start/end-of-word
> fragment apart.

## Features — what they unlock

**Features** are chosen per analyzer at creation time. They decide which
metadata is kept in the index, hence which `SEARCH` operators and scorers are
available afterwards. They are catalogued in
[`AnalyzerFeature`](../../../src/oihana/arango/clients/analyzer/enums/AnalyzerFeature.php):

| Feature | Without it, you don't get… |
|---|---|
| `FREQUENCY` | `BM25()` / `TFIDF()` scoring (relevance) |
| `NORM` | `BM25()` length normalization (short fields are no longer unfairly favored) |
| `POSITION` | `PHRASE()` — exact-phrase matching |
| `OFFSET` | snippet highlighting (implies `POSITION`) |

> Each feature costs disk space and write-time CPU — enable only what your
> queries need. For relevance-ranked View search (`BM25`, phrase bonus), the
> useful trio is `FREQUENCY` + `POSITION` + `NORM`.

## Creating an analyzer the right way

A custom analyzer is **not auto-created** by models. It must exist on the server
**before** a View references it — otherwise `viewDiff()` returns an `INVALID`
report ("analyzer not found") and the View is never created.

Two ways to create it:

- **Ad-hoc / bootstrap** — `createAnalyzer()` (shortcut for
  `$db->analyzer($name)->create($options, $features)`), handy in a setup script
  or a test:

  ```php
  $analyzer = $db->analyzer( 'text_fr_custom' ) ;   // factory, no HTTP call

  $analyzer->exists() ;                  // bool
  $analyzer->get() ;                     // raw description: type, properties, features
  $analyzer->drop( force: true ) ;       // force: true to drop even when used by a View
  ```

  `$db->analyzers()` returns one `Analyzer` handle per analyzer;
  `$db->listAnalyzers()` returns the raw descriptions. Both include the built-ins
  (`identity`, `text_en`, …).

- **Versioned migration** *(recommended for deployment)* — create the analyzer
  in a migration (`arango:migrate`), so it is provisioned reproducibly before the
  Views that depend on it. See [Migration tooling](../commands/arangodb.md).

> **Declarative registry.** You can group your custom analyzers in a list of
> `AnalyzerDefinition` under the `ArangoCommandParam::ANALYZERS` key — at the
> database level, like `collectionIndexes` for indexes (a single
> `AnalyzerDefinition` is tolerated in place of a list). It is the single source
> the `arango:analyzers` command (and `doctor`) reads to diagnose and provision them.

> **Programmatic diff / sync.** The `ArangoDB` façade exposes
> `analyzerDiff( AnalyzerDefinition )` (compares the declaration to the server →
> `MISSING` / `IN_SYNC` / `DRIFTED` / `INVALID`) and `analyzerSync()` (creates the
> **missing** ones, and only **reports** drifted ones — an analyzer being
> immutable, fixing it stays a deliberate operation). `analyzerDependentViews()`
> lists the Views that reference an analyzer (what a drop + recreate would
> affect). These are the building blocks of the `arango:analyzers` command.
>
> To repair a drift in place, `analyzerSync( $def, force: true )` runs the
> cascade: drop + recreate the analyzer **then** rebuild the inverted index of
> every dependent View (remove + re-add its link — the only thing the server
> treats as a real rebuild). ⚠️ Not transactional: between the drop and the
> recreate the analyzer briefly does not exist. The no-downtime path remains a
> new-name migration (see below).

> **Deployment / dump.** Analyzers live in the **system** `_analyzers`
> collection. `arangodump` only saves it with `--include-system-collections` —
> the built-ins (`text_fr`, …) are always there, but your **custom** analyzers
> must be recreated (migration) or explicitly included in the dump.

## Lifecycle — the `arango:analyzers` command

Once your analyzers are in the [declarative registry](#creating-an-analyzer-the-right-way),
the [`arango:analyzers`](../commands/arangodb.md#analyzers--custom-analyzer-management)
command diagnoses and provisions them like Views and indexes — `--diff` (report),
`--sync` (create the missing ones, signal the drifted ones), `--sync --force`
(repair a drift in place, cascading to the dependent Views). Two more modes
matter for the immutability constraint:

- **Repair via a migration (`--fix`)** — because an analyzer is immutable,
  repairing a drift means a same-name drop + recreate (**path B**) plus a
  rebuild of every dependent View. `--fix` writes that as a ready-to-review
  **repair migration** (one per drifted analyzer) and touches no database;
  review it, then run `migrate`. This is the deferred, versioned form of
  `--sync --force`. There is also a no-downtime **path A** — create the analyzer
  under a **new name**, point the model's `Search::ANALYZER` at it, `viewSync()`,
  then drop the old one — which stays a documented manual change (it edits the
  model) rather than a generated one.
- **Prune the orphans (`--prune`)** — drops the **orphan** custom analyzers (on
  the server, declared by none), after confirmation. An orphan still used by a
  View is only dropped with `--force` (it leaves the View dangling).
  > ⚠️ **Shared database.** An orphan analyzer may belong to **another
  > application** sharing the same database — analyzers are database-scoped, not
  > model-scoped. `--prune` is opt-in for exactly this reason: review the list
  > before confirming. Built-in (`identity`, `text_*`) and declared analyzers
  > are never pruned.

## Wiring an analyzer to a model / View

On the `Documents` model you never manipulate the analyzer directly: you declare
its **name** in the `AQL::VIEW` block, at the View level or per field.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Search ;
use oihana\arango\clients\analyzer\enums\BuiltinAnalyzer ;

AQL::VIEW =>
[
    Search::NAME     => 'placesView' ,
    Search::ANALYZER => BuiltinAnalyzer::TEXT_FR ,   // View default
    Search::FIELDS   =>
    [
        'name' => 3 ,                                          // inherits text_fr
        'code' => [ Search::ANALYZER => 'identity' ] ,         // exact token (no drift)
    ] ,
] ,
```

Resolution rule: a field declaring `Search::ANALYZER` wins; otherwise it
inherits the View's analyzer (itself `identity` by default). The details
(resolution, generated AQL, localized `?lang=` search) are in
[View search — Per-field Analyzer](search/per-field-options.md#per-field-analyzer).

## Current limitations

The library exposes the five types above (`identity`, `norm`, `stem`, `text`,
`ngram`). The other ArangoDB types — `pipeline`, `aql`, `geo_json` / `geo_point` /
`geo_s2`, `segmentation`, `collation`, `minhash`, `delimiter` /
`multi_delimiter`, `stopwords`, `classification`, `nearest_neighbors` — are
**not yet** exposed by a dedicated class (planned for later). Until then, such an
analyzer is created outside the library (direct `/_api/analyzer` HTTP API or
`arangosh`), then referenced by name in a View like any other.

## See also

- [View search (ArangoSearch)](search/README.md) — declaring a View and relevance-ranked search; per-field Analyzer, `?lang=`.
- [Understanding ArangoSearch](../getting-started/arangosearch.md) — the conceptual introduction (Analyzers, Views, `SEARCH`, scoring).
- [ArangoSearch client](../clients/arangosearch.md) — the low-level client API (Views, links, lifecycle).
- [AQL ArangoSearch functions](../aql/aql-functions-search.md) — `BM25()`, `PHRASE()`, `TOKENS()`, etc.
