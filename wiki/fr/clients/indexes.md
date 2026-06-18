# Indexes

Les indexes font toute la différence entre une requête rapide et un *full collection scan*. La couche client expose sept classes d'index typées plus une porte de sortie brute. Cette page couvre comment les créer, les supprimer et les inspecter via `Collection`.

> Pour la couche `db/` haut niveau du framework (déclarer les indexes dans le schéma d'une collection), voir [Indexes et gestion des collections](../indexes.md). Les classes documentées ici sont plus bas niveau et utilisées directement par le client pour parler à `/_api/index`.

## Méthodes de `Collection`

```php
use oihana\arango\clients\collection\indexes\PersistentIndex ;

$users = $db->collection( 'users' ) ;

$users->createIndex( new PersistentIndex(
    fields : [ 'email' ] ,
    unique : true ,
    sparse : true ,
) ) ;

$all = $users->indexes() ;            // tableau de descriptions serveur brutes
$one = $users->index( 'idx_email' ) ; // un seul index par id ou nom
$users->dropIndex( 'idx_email' ) ;
```

| Méthode | Retour | Endpoint |
|---|---|---|
| `createIndex( IndexDefinition $def )` | `array` — réponse serveur avec `id`, `type`, `fields`, métadonnées spécifiques au type | `POST /_api/index` |
| `dropIndex( string $idOrHandle )` | `void` | `DELETE /_api/index/{handle}` |
| `index( string $idOrHandle )` | `array` — définition brute | `GET /_api/index/{handle}` |
| `indexes()` | `array` — liste des définitions brutes, y compris les indexes natifs `primary` et `edge` | `GET /_api/index?collection=…` |

`idOrHandle` accepte soit un handle complet (`users/idx_email`), soit seulement la partie clé/nom (`idx_email`) — le nom de collection est préfixé automatiquement.

Les quatre méthodes lèvent `ArangoException` en cas d'erreur transport ou serveur. `index()` lève si l'index n'existe pas.

## Classes d'index typées

Toutes les classes sont des value objects `readonly` qui implémentent `IndexDefinition`. Elles sérialisent au format wire via `toArray()`. Utilisez les arguments nommés du constructeur.

### `PersistentIndex` — le défaut

Index B-tree. Couvre l'immense majorité des besoins : lookups, contraintes d'unicité, clé de tri.

```php
new PersistentIndex(
    fields       : [ 'email' ] ,
    unique       : true ,
    sparse       : true ,            // ignore les documents sans ce champ
    name         : 'idx_email' ,
    deduplicate  : true ,            // pour les champs à valeur tableau
    estimates    : true ,            // estimations de sélectivité pour l'optimiseur
    cacheEnabled : false ,
    storedValues : [ 'name' ] ,       // index couvrant — lire ces champs sans toucher le doc
    inBackground : true ,             // construit sans verrouiller la collection
) ;
```

### `GeoIndex` — géospatial

```php
// Point en deux champs (lat, lng)
new GeoIndex( fields: [ 'lat' , 'lng' ] ) ;

// Objet GeoJSON unique
new GeoIndex( fields: [ 'location' ] , geoJson: true ) ;
```

### `TtlIndex` — expiration automatique

```php
new TtlIndex(
    fields      : [ 'createdAt' ] ,    // exactement un champ
    expireAfter : 86_400 ,             // secondes — les documents plus anciens sont supprimés
) ;
```

Le champ doit contenir un epoch numérique ou une chaîne ISO-8601. L'expiration est asynchrone (best-effort) — ne pas s'appuyer dessus pour la sécurité ou des deadlines strictes.

### `MDIIndex` — multi-dimensionnel (3.12+)

```php
new MDIIndex(
    fields : [ 'x' , 'y' , 'z' ] ,
    unique : false ,
) ;

// Variante préfixée — accélère les requêtes qui filtrent toujours sur le préfixe.
new MDIIndex(
    fields       : [ 'x' , 'y' ] ,
    prefixFields : [ 'tenantId' ] ,
) ;
```

`fieldValueTypes` vaut `'double'` par défaut — seule valeur acceptée aujourd'hui.

### `VectorIndex` — recherche par similarité (3.13+)

```php
new VectorIndex(
    fields      : [ 'embedding' ] ,
    params      : [
        'dimensions' => 1536 ,
        'metric'     => 'cosine' ,    // ou 'l2'
        'nLists'     => 100 ,
    ] ,
    parallelism : 4 ,
) ;
```

`params` reflète la configuration Faiss sous-jacente — voir la doc officielle [Vector indexes](https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/vector-indexes/) pour la liste complète.

### `InvertedIndex` — full-text moderne (3.10+)

Pour de la recherche full-text sur une seule collection. Pour du full-text multi-collections, préférez une [vue ArangoSearch](arangosearch.md).

```php
new InvertedIndex(
    fields           : [ 'title' , 'body' ] ,
    analyzer         : 'text_en' ,
    features         : [ 'frequency' , 'position' , 'norm' ] ,
    includeAllFields : false ,
    primarySort      : [ [ 'field' => 'createdAt' , 'direction' => 'desc' ] ] ,
    storedValues     : [ 'authorId' ] ,
    parallelism      : 2 ,
) ;
```

#### Différence (diff) d'un index inversé

`indexesDiff()` et `arango:doctor` réconcilient un index inversé de façon **canonique**. Le serveur normalise ce qu'il stocke : un champ déclaré sous forme de chaîne (`'title'`) est renvoyé sous forme d'objet `{ name: 'title' }`, la direction de `primarySort` s'écrit `{ asc: true }` plutôt que `{ direction: 'asc' }`, et les valeurs par défaut omises par la déclaration (`compression`, les indicateurs par champ, …) sont complétées. Ces normalisations sont absorbées avant la comparaison : un index inversé réellement à jour ne ressort donc plus comme un faux drift — seule une vraie divergence (un `primarySort` différent, un `storedValues` supprimé, …) est signalée.

Un objet `InvertedIndex` peut être déclaré **directement** — dans le [registre `collectionIndexes`](../commands/arangodb.md), ou passé à `indexesDiff()` / `createIndex()` — au lieu d'être écrit à la main sous forme de tableau brut :

```php
$collectionIndexes = [
    'articles' => new InvertedIndex( fields: [ 'title' , 'body' ] , name: 'inv_search' , analyzer: 'text_en' ) ,
] ;
```

### `FulltextIndex` — legacy

Déprécié depuis ArangoDB 3.10 au profit d'`InvertedIndex`. Toujours présent pour la rétrocompatibilité :

```php
new FulltextIndex(
    fields    : [ 'body' ] ,
    minLength : 3 ,
) ;
```

À ne pas utiliser pour du nouveau code.

### `RawIndexDefinition` — porte de sortie

Quand le type d'index n'est pas représenté par une classe typée — ou quand vous testez une nouvelle feature d'ArangoDB en avance sur la bibliothèque :

```php
use oihana\arango\clients\collection\indexes\RawIndexDefinition ;

$users->createIndex( new RawIndexDefinition([
    'type'   => 'persistent' ,
    'fields' => [ 'email' ] ,
    'unique' => true ,
]) ) ;
```

Le tableau est envoyé verbatim comme corps de requête. La validation incombe au serveur.

## L'enum `IndexType`

```php
use oihana\arango\clients\collection\indexes\enums\IndexType ;

IndexType::PERSISTENT ;
IndexType::GEO ;
IndexType::TTL ;
IndexType::MDI ;
IndexType::MDI_PREFIXED ;
IndexType::VECTOR ;
IndexType::INVERTED ;
IndexType::FULLTEXT ;     // déprécié
IndexType::PRIMARY ;       // géré par le serveur — pas de création manuelle
IndexType::EDGE ;          // géré par le serveur — pas de création manuelle
```

À utiliser pour matcher la chaîne `type` renvoyée par le serveur dans `indexes()`.

## Une recette complète — index unique sur email

```php
use oihana\arango\clients\collection\indexes\PersistentIndex ;

$meta = $db->collection( 'users' )->createIndex(
    new PersistentIndex(
        fields : [ 'email' ] ,
        unique : true ,
        sparse : true ,
        name   : 'idx_email_unique' ,
    )
) ;

echo $meta[ 'id' ] ;       // 'users/12345'
echo $meta[ 'name' ] ;     // 'idx_email_unique'
echo $meta[ 'unique' ] ;   // true
```

`unique: true` impose l'unicité de l'email dans la collection. `sparse: true` exclut de l'index les documents sans champ `email` — sans cela, vous rejetteriez des documents qui omettent légitimement le champ. La combinaison est le pattern canonique pour les champs optionnels-mais-uniques.

## Aller plus loin

- [Indexes et gestion des collections](../indexes.md) — la couche `db/` haut niveau avec les classes `IndexOptions` et `CollectionManagementTrait`.
- [ArangoSearch](arangosearch.md) — full-text multi-collections via analyzers et views.
- [Vue d'ensemble du client HTTP](README.md) — architecture et configuration.
