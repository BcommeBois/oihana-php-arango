# Indexes et gestion des collections

Cette page couvre la **gestion des collections et des index** côté framework : comment créer, supprimer, inspecter ; quel type d'index choisir selon le besoin ; et le pattern recommandé de déclaration *lazy* via `AQL::INDEXES` sur un modèle.

Le détail des **propriétés** de chaque classe `*IndexOptions` est dans la [Référence des options AQL](options.md#options-dindex--optionsindexes). Le détail des **méthodes** `collection*` et `*Index` est dans le [Quickstart `ArangoDB`](quickstart.md#gérer-les-collections). Cette page se concentre sur le **comment choisir** et le **comment déployer**.

## Choisir un type d'index

ArangoDB expose six types d'index utilisables par le framework. Le bon choix dépend du pattern d'accès, du volume, et des contraintes d'unicité.

| Type | Quand l'utiliser | Champs typés | Caveat |
|---|---|---|---|
| `persistent` | Lookups par égalité ou range sur un champ. Contraintes d'unicité (email, identifiant métier). | scalaires, dates | Bloque les écritures pendant la création (sauf `inBackground: true`). |
| `ttl` | Expiration automatique d'un document (sessions, tokens, caches). | un champ date ou timestamp | Le document est supprimé dès que `field < now - expireAfter`. |
| `geo` | Requêtes géospatiales (distance, *within bounding box*). | coordonnées `[lat, lng]` ou GeoJSON | Différent de `geo_legacy` ; utiliser `geoJson: true` pour le format standard. |
| `mdi` | Lookups multi-dimensionnels (timestamp + status + region simultanément). | plusieurs champs | Complexe à dimensionner ; mesurer avant d'adopter. |
| `vector` | Recherche par similarité (*embeddings*, *cosine*). ArangoDB 3.12+. | champ tableau de floats | Requiert édition Enterprise pour certaines features Faiss. |
| `fulltext` *(deprecated)* | Recherche textuelle simple. | un champ chaîne | Remplacé par les vues ArangoSearch — éviter pour les nouvelles fonctionnalités. |

Pour la recherche textuelle avancée (analyseurs linguistiques, BM25, facettes), préférer les **vues ArangoSearch** plutôt qu'un index `fulltext`. Ces vues ne sont pas wrappées par `oihana/arango` à ce jour — elles s'utilisent directement via une clause `aqlSearch()` dans une requête AQL custom.

## Méthodes de gestion

`ArangoDB` expose quatre méthodes pour les index, via [`CollectionManagementTrait`](../../../api/src/oihana/arango/db/traits/CollectionManagementTrait.php).

| Méthode | Description |
|---|---|
| `createIndex( $collection , $options )` | Crée un index. Accepte un tableau brut ou une instance d'`IndexOptions` (sérialisée auto). Retourne la définition serveur ou `null` en cas d'erreur. |
| `dropIndex( $collection , $indexHandle )` | Supprime un index par son *handle* (de la forme `collection/index-id`). |
| `getIndex( $collection , $indexId )` | Renvoie la définition d'un index. |
| `getIndexes( $collection )` | Liste tous les index d'une collection. |

```php
use oihana\arango\db\options\indexes\PersistentIndexOptions ;

$index           = new PersistentIndexOptions() ;
$index->fields   = [ 'email' ] ;
$index->unique   = true        ;
$index->sparse   = true        ;
$index->name     = 'idx_users_email_unique' ; // optionnel mais recommandé pour le debug

$db->createIndex( 'users' , $index ) ;
```

> Toujours nommer un index (`name`) — sinon ArangoDB en génère un automatiquement et il devient difficile à identifier dans les *logs* et les outils d'admin.

## Pattern recommandé — déclaration *lazy* via `AQL::INDEXES`

Plutôt que d'appeler manuellement `createIndex()` dans un script de migration, déclarer les index dans `AQL::INDEXES` du modèle. Ils sont créés automatiquement **à la première instanciation** du modèle (et donc au premier appel API en production), uniquement s'ils n'existent pas déjà.

```php
use oihana\arango\db\options\indexes\PersistentIndexOptions ;
use oihana\arango\db\options\indexes\TTLIndexOptions ;

return
[
    Models::SESSIONS => fn( Container $c ) => new Documents( $c ,
    [
        AQL::COLLECTION => 'sessions'           ,
        AQL::DATABASE   => Databases::ARANGO    ,
        AQL::INDEXES    =>
        [
            // Lookup unique par tokenHash
            ( function ()
            {
                $idx           = new PersistentIndexOptions() ;
                $idx->fields   = [ 'tokenHash' ] ;
                $idx->unique   = true            ;
                $idx->sparse   = true            ;
                $idx->name     = 'idx_sessions_tokenHash' ;
                return $idx ;
            } )() ,

            // Expiration automatique
            ( function ()
            {
                $ttl              = new TTLIndexOptions() ;
                $ttl->fields      = [ 'expiresAt' ] ;
                $ttl->expireAfter = 0               ;
                $ttl->name        = 'idx_sessions_ttl' ;
                return $ttl ;
            } )() ,
        ] ,
    ]) ,
] ;
```

Avantages :

- **Pas de script de migration séparé** — les index suivent le modèle.
- **Idempotent** — si l'index existe déjà avec le même nom, `createIndex()` est *no-op*.
- **Versionné avec le code** — la définition d'index vit dans la même *definition* DI que la collection.

Limite : la création d'index `persistent` lourd peut **bloquer les écritures** pendant plusieurs minutes sur une grosse collection. Pour ce cas, passer `inBackground: true` dans les options.

## Catalogue des classes par type

Détaillé dans [Référence des options AQL — Options d'index](options.md#options-dindex--optionsindexes). Résumé des classes disponibles :

| Classe | Type d'index produit |
|---|---|
| `IndexOptions` | Base abstraite (ne pas instancier directement) |
| `PersistentIndexOptions` | `persistent` |
| `TTLIndexOptions` | `ttl` |
| `GeoIndexOptions` | `geo` |
| `MDIIndexOptions` | `mdi` |
| `VectorIndexOptions` | `vector` |

Toutes ces classes :

- Héritent de `IndexOptions` (et donc des propriétés communes `fields`, `name`, `inBackground`, `sparse`).
- Implémentent `JsonSerializable` — `json_encode($options)` produit le JSON consommé par le moteur ArangoDB.
- Ajoutent des propriétés spécifiques au type (`expireAfter` pour TTL, `geoJson` pour Geo, `params` pour Vector...).

## Cas d'usage par type

### Lookup unique — `persistent` + `unique`

```php
$idx           = new PersistentIndexOptions() ;
$idx->fields   = [ 'email' ] ;
$idx->unique   = true        ;
$idx->sparse   = true        ;

$db->createIndex( 'users' , $idx ) ;
```

L'option `sparse: true` exclut les documents où `email` est absent — utile pour ne pas bloquer l'insertion de comptes en cours d'invitation où l'email n'est pas encore défini.

### Composé — `persistent` multi-champs

```php
$idx           = new PersistentIndexOptions() ;
$idx->fields   = [ 'status' , 'createdAt' ] ; // ordre important
$idx->name     = 'idx_orders_status_created' ;

$db->createIndex( 'orders' , $idx ) ;
```

L'ordre des champs détermine quelles requêtes bénéficient de l'index. Un index sur `[status, createdAt]` accélère `FILTER status == @s AND createdAt > @d` mais pas `FILTER createdAt > @d` seul (lookups par préfixe uniquement).

### Expiration automatique — `ttl`

```php
$ttl              = new TTLIndexOptions() ;
$ttl->fields      = [ 'expiresAt' ] ;
$ttl->expireAfter = 0               ; // expire dès que expiresAt < now
$ttl->name        = 'idx_sessions_ttl' ;

$db->createIndex( 'sessions' , $ttl ) ;
```

Le champ doit être un timestamp Unix (en secondes) ou une date ISO 8601. Le scan d'expiration tourne périodiquement côté serveur (env. toutes les 30 secondes par défaut).

### Géospatial — `geo` + GeoJSON

```php
$geo          = new GeoIndexOptions() ;
$geo->fields  = [ 'location' ] ;
$geo->geoJson = true            ;
$geo->name    = 'idx_places_geo' ;

$db->createIndex( 'places' , $geo ) ;
```

Documents :

```json
{ "name": "Eiffel Tower", "location": { "type": "Point", "coordinates": [2.2945, 48.8584] } }
```

Requête typique :

```aql
FOR p IN places
  FILTER GEO_DISTANCE([2.3522, 48.8566], p.location) < 5000  // < 5 km de Paris
  RETURN p
```

### Recherche par similarité — `vector`

```php
$vec          = new VectorIndexOptions() ;
$vec->fields  = [ 'embedding' ] ;
$vec->params  =
[
    'dimension'    => 768          ,
    'metric'       => 'cosine'     ,
    FaithParam::NLISTS => 1000     ,
] ;
$vec->name = 'idx_documents_vector' ;

$db->createIndex( 'documents' , $vec ) ;
```

La dimension doit matcher exactement la taille du vecteur. La métrique (`cosine`, `l2`, `inner_product`) dépend du modèle d'*embedding* utilisé pour générer le vecteur.

## Inspection à l'exécution

```php
// Liste tous les index d'une collection
$indexes = $db->getIndexes( 'users' ) ;

foreach ( $indexes as $idx )
{
    echo $idx[ 'id' ] . ' (' . $idx[ 'type' ] . ')' . PHP_EOL ;
}
```

Utile pour les commandes de *doctor* ou les rapports d'admin. La liste inclut systématiquement les index implicites (`primary` sur `_key`, `edge` sur `_from`/`_to` pour les *edge collections*).

## Bonnes pratiques

- **Toujours nommer les index** (`name`) pour le debug et le suivi.
- **`inBackground: true`** sur les collections en production avec écritures fréquentes.
- **Sparse quand pertinent** — `sparse: true` réduit la taille de l'index si beaucoup de documents n'ont pas le champ.
- **`unique: true` avec parcimonie** — augmente le coût en écriture (vérification).
- **Surveiller la sélectivité** via `getIndexes()` + `estimates` (option à activer sur `PersistentIndexOptions`).
- **Pas d'index sur des champs jamais filtrés** — chaque index coûte en espace et en performance d'écriture.

## Voir aussi

- [Quickstart `ArangoDB`](quickstart.md#gérer-les-index) — méthodes `createIndex` / `dropIndex` / `getIndex` / `getIndexes`.
- [Référence des options AQL — Options d'index](options.md#options-dindex--optionsindexes) — détail des propriétés par classe.
- [Modèles `Documents` et `Edges`](models.md) — clé `AQL::INDEXES` pour la déclaration *lazy*.
- [Documentation officielle ArangoDB — Working with indexes](https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/).
