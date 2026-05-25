# ArangoSearch

ArangoSearch est le moteur de recherche full-text intégré à ArangoDB. Deux briques :

- Un **Analyzer** décrit la manière dont le texte est tokenisé et normalisé — lowercasing, stemming, suppression d'accents, segmentation par langue.
- Une **View** est un index inversé interrogeable qui relie une ou plusieurs collections à un ou plusieurs analyzers, exposés via la clause AQL `SEARCH ... IN view`.

Cette page couvre les deux surfaces côté client. Pour du full-text sur **une seule** collection, vous pouvez aussi utiliser un [`InvertedIndex`](indexes.md#invertedindex--full-text-moderne-310) — les Views deviennent intéressantes dès qu'il faut chercher sur plusieurs collections ou classer les résultats.

## Analyzers

### Créer un analyzer

```php
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;

$db->createAnalyzer(
    'text_fr' ,
    new TextAnalyzer(
        locale   : 'fr.utf-8' ,
        case     : 'lower' ,
        accent   : false ,        // replier les accents
        stemming : true ,
    ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
) ;
```

`createAnalyzer()` est le raccourci pour `$db->analyzer( $name )->create( ... )`.

### Classes d'analyzers

Les quatre sont des value objects readonly qui implémentent `AnalyzerOptions::toArray()` :

| Classe | Ce qu'elle fait | Paramètres du constructeur |
|---|---|---|
| `IdentityAnalyzer` | Pass-through — aucune transformation. Utilisé par défaut si aucun analyzer n'est posé sur un link. | (aucun) |
| `NormAnalyzer` | Case folding locale-aware + suppression d'accent optionnelle. Pas de tokenisation. | `locale`, `?case`, `?accent` |
| `StemAnalyzer` | Stemming locale-aware. L'entrée doit déjà être tokenisée. | `locale` |
| `TextAnalyzer` | Tokeniseur full-text + stemming, stopwords, accents, edge n-grams optionnels. **La bête de somme.** | `locale`, `?case`, `?accent`, `?stemming`, `?stopwords`, `?stopwordsPath`, `?edgeNgram` |

### Features d'analyzer

Choisies par analyzer à la création, elles déterminent quelles données supplémentaires sont conservées dans l'index :

| Feature | Requise par |
|---|---|
| `FREQUENCY` | scoring `BM25()` et `TFIDF()` |
| `NORM` | normalisation par longueur de `BM25()` |
| `POSITION` | matching `PHRASE()` |
| `OFFSET` | mise en évidence des résultats (implique `POSITION`) |

Coûte plus d'espace et de CPU à l'écriture — n'activez que ce dont vos requêtes ont besoin.

### Méthodes de cycle de vie

```php
$analyzer = $db->analyzer( 'text_fr' ) ;       // factory, pas de HTTP

$analyzer->exists() ;                          // bool
$analyzer->get() ;                             // description brute : type, properties, features
$analyzer->drop( force: false ) ;              // passer force: true pour supprimer même utilisé par une view
```

`$db->analyzers()` renvoie des handles `Analyzer` pour chaque analyzer ; `$db->listAnalyzers()` renvoie les descriptions brutes. Les deux incluent les analyzers intégrés (`identity`, `text_en`, etc.).

## Views

### Créer une view

Une view est décrite par une map `links` : nom de collection → config `ArangoSearchLink`.

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

### Paramètres `ArangoSearchLink`

```php
new ArangoSearchLink(
    analyzers          : null ,  // liste de noms d'analyzers — défaut [ 'identity' ]
    fields             : null ,  // récursif : map champ → ArangoSearchLink
    includeAllFields   : null ,  // indexe tous les attributs (override `fields`)
    trackListPositions : null ,  // retenir la position ordinale dans les champs tableau
    storeValues        : null ,  // 'none' (défaut) ou 'id' (active les covering queries)
    inBackground       : null ,  // top-level uniquement — construit sans bloquer les écritures
) ;
```

La récursion `fields` permet de lier un ensemble d'analyzers différent à chaque chemin. Les `analyzers` d'un champ surchargent ceux du link parent.

### Méthodes de cycle de vie

```php
$view = $db->view( 'articles_search' ) ;   // factory, pas de HTTP

$view->exists() ;          // bool
$view->get() ;             // bref : name, type, id
$view->properties() ;      // config complète : links + paramètres de consolidation
$view->drop() ;            // les collections sont préservées
```

### Mettre à jour les propriétés

`updateProperties()` et `replaceProperties()` reflètent les sémantiques PATCH et PUT :

```php
// Fusion additive — utile pour ajouter un nouveau link de collection.
$view->updateProperties([
    'links' => [
        'comments' => ( new ArangoSearchLink( analyzers: [ 'text_fr' ] ) )->toArray() ,
    ] ,
]) ;

// Remplacement complet — les champs absents reviennent à leurs défauts.
$view->replaceProperties([
    'links' => [
        'articles' => ( new ArangoSearchLink( analyzers: [ 'text_fr' ] ) )->toArray() ,
    ] ,
]) ;
```

## Rechercher depuis AQL

Une view se consomme via la clause `SEARCH ... IN view` :

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

Opérateurs à connaître :

| Fonction AQL | Effet | Feature requise |
|---|---|---|
| `ANALYZER( cond , 'name' )` | Lie un analyzer à une sous-condition | — |
| `PHRASE( field , 'mots' , 'analyzer' )` | Match de phrase | `POSITION` |
| `STARTS_WITH( field , 'préfixe' )` | Match par préfixe (avec analyzer text edge-n-gram) | — |
| `TOKENS( 'input' , 'analyzer' )` | Tokenise une chaîne avec un analyzer | — |
| `BM25( doc , k , b )` | Score de pertinence (Okapi BM25) | `FREQUENCY`, `NORM` (optionnel) |
| `TFIDF( doc , false )` | Score TF-IDF | `FREQUENCY` |

Voir la [référence officielle ArangoSearch](https://docs.arangodb.com/stable/index-and-search/arangosearch/) pour le catalogue complet.

## Une recette complète

```php
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;
use oihana\arango\clients\view\ArangoSearchLink ;
use function oihana\arango\clients\aql\helpers\aql ;

// 1. Analyzer pour le texte français.
$db->createAnalyzer(
    'text_fr' ,
    new TextAnalyzer( locale: 'fr.utf-8' , case: 'lower' , accent: false , stemming: true ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::NORM , AnalyzerFeature::POSITION ] ,
) ;

// 2. View qui relie la collection 'articles'.
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

// 3. Recherche avec classement BM25.
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

## Aller plus loin

- [Requêtes AQL et Cursors](aql.md) — écrire les requêtes `SEARCH` qui consomment vos views.
- [Indexes](indexes.md) — full-text mono-collection via `InvertedIndex`.
- [Vue d'ensemble du client HTTP](README.md) — architecture et configuration.
