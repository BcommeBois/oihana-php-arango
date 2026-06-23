# ArangoSearch

ArangoSearch est le moteur de recherche full-text intégré à ArangoDB. Deux briques :

- Un **Analyzer** décrit la manière dont le texte est tokenisé et normalisé — lowercasing, stemming, suppression d'accents, segmentation par langue.
- Une **View** est un index inversé interrogeable qui relie une ou plusieurs collections à un ou plusieurs analyzers, exposés via la clause AQL `SEARCH ... IN view`.

Les concepts sont nouveaux pour vous ? Commencez par lire notre page dédiée [Comprendre ArangoSearch](../getting-started/arangosearch.md). Cette page couvre les deux surfaces côté client. Pour du full-text sur **une seule** collection, vous pouvez aussi utiliser un [`InvertedIndex`](indexes.md#invertedindex--full-text-moderne-310) — les Views deviennent intéressantes dès qu'il faut chercher sur plusieurs collections ou classer les résultats.

## Analyzers

> **Page dédiée :** les types d'analyzers, ce que chacun produit, les features
> et comment fabriquer un analyzer custom sont expliqués en détail dans
> **[Analyzers](../db/analyzers.md)**. Cette section ne couvre que l'API de
> cycle de vie côté client.

Créer un analyzer (raccourci de `$db->analyzer( $name )->create( ... )`) :

```php
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;

$db->createAnalyzer(
    'text_fr_custom' ,
    new TextAnalyzer( locale: 'fr.utf-8' , case: 'lower' , accent: false , stemming: true ) ,
    [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
) ;
```

Les quatre classes d'options (`IdentityAnalyzer`, `NormAnalyzer`, `StemAnalyzer`,
`TextAnalyzer`) et leurs features sont décrites dans [Analyzers](../db/analyzers.md).

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

### Vues `search-alias`

Le second type de vue, `search-alias`, ne **possède pas** son index. C'est un simple alias
au-dessus d'**un index `inverted` par collection** — chaque collection possède et gère son
propre index (partageable, cycle de vie indépendant), et la vue ne fait que les agréger.
C'est le substrat naturel d'une **recherche fédérée multi-collections** (une seule barre de
recherche sur `customers`, `products`, …).

On déclare d'abord un index inversé sur chaque collection, puis on crée l'alias :

```php
use oihana\arango\clients\collection\indexes\InvertedIndex ;
use oihana\arango\db\options\views\SearchAliasView ;

// 1. un index inversé par collection (type d'index de plein droit)
$inv = new InvertedIndex( fields: [ 'name' , 'email' ] , name: 'inv_search' , analyzer: 'text_fr' ) ;
$db->collection( 'customers' )->createIndex( $inv ) ;
$db->collection( 'products'  )->createIndex( $inv ) ;

// 2. une vue search-alias qui les agrège
$view = new SearchAliasView( 'global_search' ,
[
    'customers' => 'inv_search' ,   // collection => nom de l'index inversé
    'products'  => 'inv_search' ,
] ) ;
$db->view( 'global_search' )->createSearchAlias( $view->getIndexes() ) ;

// 3. un seul SEARCH fédéré couvre toutes les collections aliasées
$db->query( 'FOR d IN global_search SEARCH ANALYZER(d.name IN TOKENS(@q, "text_fr"), "text_fr") SORT BM25(d) DESC RETURN d' , [ 'q' => 'dupont' ] ) ;
```

`SearchAliasView::getIndexes()` accepte soit la map `collection => index` ci-dessus, soit une
liste explicite `[ [ 'collection' => 'customers', 'index' => 'inv_search' ], … ]`. Pour une
configuration déclarative et gérée, déclarez les vues dans le registre `searchAliasViews`
(`ArangoSearchAliasViewsTrait`) — le pendant niveau-base du registre d'analyzers.

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

- [Comprendre ArangoSearch](../getting-started/arangosearch.md) — l'introduction aux concepts (Analyzers, Views, `SEARCH`, scoring) et la carte étage-par-étage de la bibliothèque.
- [Recherche View (ArangoSearch)](../db/search/overview.md) — déclarer une View sur un modèle `Documents` : `?search=` classé par pertinence avec auto-provisioning.
- [Fonctions ArangoSearch](../aql/aql-functions-search.md) — les helpers d'expressions `SEARCH` (`phrase`, `boost`, `bm25`, …).
- [Requêtes AQL et Cursors](aql.md) — écrire les requêtes `SEARCH` qui consomment vos views.
- [Indexes](indexes.md) — full-text mono-collection via `InvertedIndex`.
- [Vue d'ensemble du client HTTP](README.md) — architecture et configuration.
