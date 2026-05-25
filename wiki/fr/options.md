# Référence des options AQL

Le dossier [`api/src/oihana/arango/db/options/`](../../../api/src/oihana/arango/db/options/) regroupe **15 classes** qui modélisent les options de chaque opération AQL et de chaque type d'index. Ce sont des objets hydratables, validés et sérialisables — produits intermédiaires entre la déclaration applicative (un tableau PHP) et la clause `OPTIONS { ... }` AQL générée par [`aqlOptions()`](aql/aql-operations.md#aqloptions).

## Le pattern

Toutes les classes d'options partagent la même mécanique :

1. **Hydratables depuis un tableau** : `new SomeOptions([ 'useCache' => true ])` initialise les propriétés depuis un *array* associatif. Les clés inconnues sont silencieusement ignorées (forward-compat avec les options ArangoDB futures).
2. **JsonSerializable** : `json_encode($options)` produit le JSON `{"useCache":true,...}` consommé par le moteur ArangoDB.
3. **Filtrage des valeurs `null`** : les propriétés non définies ne sont pas émises, ce qui évite d'écraser une valeur par défaut serveur avec un `null` explicite.
4. **Branchées sur `aqlOptions()`** : l'opération hôte (`aqlFor`, `aqlInsert`, ...) consomme la classe d'options appropriée et délègue à `aqlOptions()` la conversion en `OPTIONS { ... }`.

En usage applicatif, on passe rarement directement une instance — un *array* aux clés `AQL::*` suffit, le framework instancie l'`Options` correspondante en interne.

```php
// Forme typique (array hydraté à l'intérieur de aqlFor)
aqlFor
([
    AQL::DOC_REF => 'doc' ,
    AQL::IN      => 'users' ,
    AQL::OPTIONS =>
    [
        'indexHint'      => 'byEmail'  ,
        'forceIndexHint' => true       ,
        'useCache'       => true       ,
    ] ,
]) ;

// Équivalent avec instance explicite
$opts = new ForOptions
([
    'indexHint'      => 'byEmail' ,
    'forceIndexHint' => true      ,
]) ;

aqlFor( [ AQL::DOC_REF => 'doc' , AQL::IN => 'users' , AQL::OPTIONS => $opts ] ) ;
```

## Options par opération

Neuf classes ciblent chacune une opération AQL spécifique.

| Classe | Opération hôte | Options principales (extrait) |
|---|---|---|
| `QueryOptions` | Toutes (au niveau requête) | `cache`, `count`, `fullCount`, `maxRuntime`, `memoryLimit`, `profile`, `stream` |
| `ForOptions` | `aqlFor` (boucle) | `indexHint`, `forceIndexHint`, `disableIndex`, `useCache`, `lookahead` |
| `CollectOptions` | `aqlCollect` (agrégation) | `method` (`sorted`, `hash`) |
| `InsertOptions` | `aqlInsert` | `ignoreErrors`, `waitForSync`, `overwriteMode`, `keepNull`, `mergeObjects` |
| `UpdateOptions` | `aqlUpdate` | `ignoreErrors`, `keepNull`, `mergeObjects`, `waitForSync`, `ignoreRevs`, `exclusive` |
| `ReplaceOptions` | `aqlReplace` | `ignoreErrors`, `waitForSync`, `ignoreRevs`, `exclusive` |
| `RemoveOptions` | `aqlRemove` | `ignoreErrors`, `waitForSync`, `ignoreRevs`, `exclusive` |
| `UpsertOptions` | `aqlUpsert` | Combinaison Insert + Update (`ignoreErrors`, `keepNull`, `mergeObjects`, `exclusive`, ...) |
| `TraversalOptions` | `aqlTraversal` | `order` (`bfs`, `dfs`, `weighted`), `uniqueVertices`, `uniqueEdges`, `bfs` (deprecated) |

> Chaque classe expose les options officielles de l'opération ArangoDB correspondante. Quand ArangoDB ajoute une option (par exemple `parallelism` pour les traversées), il suffit d'ajouter la propriété dans la classe — aucune autre couche à toucher.

### Exemples ciblés

**Insertion avec gestion des conflits :**

```php
use oihana\arango\db\enums\OverwriteMode ;

aqlInsert
([
    AQL::COLLECTION => 'users'                     ,
    AQL::DOCUMENT   => $document                   ,
    AQL::OPTIONS    =>
    [
        'ignoreErrors'   => true                   ,
        'overwriteMode'  => OverwriteMode::IGNORE  ,
        'waitForSync'    => false                  ,
    ] ,
]) ;
```

**Requête avec *full count* (pour la pagination) :**

```php
$db->prepare
([
    'query'    => 'FOR doc IN users LIMIT @offset, @count RETURN doc' ,
    'bindVars' => [ 'offset' => 0 , 'count' => 50 ] ,
    'options'  => [ 'fullCount' => true ] ,
])
   ->execute() ;

$total = $db->getFoundRows() ; // total avant LIMIT
```

**Traversée en largeur, sommets uniques :**

```php
use oihana\arango\db\enums\options\TraversalOrder ;
use oihana\arango\db\enums\options\TraversalUniqueVertices ;

aqlTraversal
([
    AQL::START     => '@startVertex' ,
    AQL::GRAPH     => 'social'        ,
    AQL::DIRECTION => Traversal::OUTBOUND ,
    AQL::OPTIONS   =>
    [
        'order'          => TraversalOrder::BFS         ,
        'uniqueVertices' => TraversalUniqueVertices::PATH ,
    ] ,
]) ;
```

## Options d'index — `options/indexes/`

Six classes modélisent les options de création d'index, consommées par [`CollectionManagementTrait::createIndex()`](quickstart.md#gérer-les-index).

| Classe | Type d'index | Options principales |
|---|---|---|
| `IndexOptions` | Base abstraite | `fields`, `name`, `inBackground`, `sparse` |
| `PersistentIndexOptions` | `persistent` | `fields`, `unique`, `sparse`, `name`, `deduplicate`, `inBackground`, `cacheEnabled`, `estimates` |
| `TTLIndexOptions` | `ttl` | `fields`, `expireAfter`, `name`, `inBackground`, `selectivityEstimate` |
| `GeoIndexOptions` | `geo` | `fields`, `geoJson`, `name`, `inBackground`, `legacyPolygons` |
| `MDIIndexOptions` | `mdi` (multi-dimensionnel) | `fields`, `fieldValueTypes`, `unique`, `sparse`, `storedValues` |
| `VectorIndexOptions` | `vector` (ArangoDB 3.12+) | `fields`, `params` (avec sous-`FaithParam` pour Faiss), `cacheEnabled` |

### Exemples

**Index `persistent` unique pour les emails :**

```php
use oihana\arango\db\options\indexes\PersistentIndexOptions ;

$index         = new PersistentIndexOptions() ;
$index->fields = [ 'email' ]    ;
$index->unique = true           ;
$index->sparse = true           ;

$db->createIndex( 'users' , $index ) ;
```

**Index TTL pour expiration automatique de sessions :**

```php
use oihana\arango\db\options\indexes\TTLIndexOptions ;

$ttl              = new TTLIndexOptions() ;
$ttl->fields      = [ 'expiresAt' ] ;
$ttl->expireAfter = 0               ;        // expire dès que expiresAt < now

$db->createIndex( 'sessions' , $ttl ) ;
```

**Index `geo` sur des coordonnées GeoJSON :**

```php
use oihana\arango\db\options\indexes\GeoIndexOptions ;

$geo          = new GeoIndexOptions() ;
$geo->fields  = [ 'location' ] ;
$geo->geoJson = true            ;

$db->createIndex( 'places' , $geo ) ;
```

**Index `vector` pour la recherche par similarité :**

```php
use oihana\arango\db\options\indexes\VectorIndexOptions ;
use oihana\arango\db\enums\FaithParam ;

$vec          = new VectorIndexOptions() ;
$vec->fields  = [ 'embedding' ] ;
$vec->params  =
[
    'dimension'    => 768          ,
    'metric'       => 'cosine'     ,
    FaithParam::NLISTS => 1000     ,
] ;

$db->createIndex( 'documents' , $vec ) ;
```

## Sérialisation et inspection

Toute classe d'options implémente `JsonSerializable`. Cela permet de l'utiliser avec `json_encode()`, de la stocker en cache, ou de la *logger* pour audit :

```php
$opts = new ForOptions( [ 'indexHint' => 'byEmail' , 'useCache' => true ] ) ;
echo json_encode( $opts ) ;
// {"indexHint":"byEmail","useCache":true}
```

Le `aqlOptions()` interne fait cet `json_encode` puis enveloppe le résultat dans `OPTIONS { ... }`.

## Voir aussi

- [Construire une requête AQL pas à pas](aql/aql-building-queries.md).
- [Opérations AQL `db/operations/`](aql/aql-operations.md) — chaque opération mentionne sa classe d'options associée.
- [Quickstart `ArangoDB` — Index](quickstart.md#gérer-les-index) — utilisation des classes d'`*IndexOptions`.
- [Référence des enums](enums.md) — `OverwriteMode`, `TraversalOrder`, `IndexType`, etc. consommés par ces options.
- [Documentation officielle AQL — options par opération](https://docs.arangodb.com/stable/aql/operations/) (chaque page d'opération liste ses options).
- [Documentation officielle Indexes](https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/).
