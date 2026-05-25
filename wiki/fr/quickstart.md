# Quickstart `ArangoDB`

La classe [`ArangoDB`](../../src/oihana/arango/db/ArangoDB.php) est le point d'entrée de toute la couche bas-niveau du framework. Elle encapsule une connexion au serveur, expose la gestion des collections et des index via le trait [`CollectionManagementTrait`](../../src/oihana/arango/db/traits/CollectionManagementTrait.php), et fournit l'exécution de requêtes AQL brutes.

Les modèles haut-niveau (`Documents`, `Edges`) et les contrôleurs Slim s'appuient tous sur cette classe. Comprendre `ArangoDB` est donc le préalable à tout le reste.

## Instanciation directe

La forme la plus simple — un tableau de configuration et un *logger* PSR-3 optionnel :

```php
use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\ArangoConfig ;

$db = new ArangoDB
([
    ArangoConfig::ENDPOINT => 'tcp://127.0.0.1:8529' ,
    ArangoConfig::DATABASE => 'my_db'                ,
    ArangoConfig::TYPE     => 'Basic'                ,
    ArangoConfig::USER     => 'root'                 ,
    ArangoConfig::PASSWORD => 'secret'               ,
] , $logger ) ;
```

La connexion est établie au constructeur. Les erreurs réseau ou serveur sont *catchées* et écrites dans le *logger* — la classe ne lève pas ; à charge de l'appelant de vérifier la suite. Cette tolérance permet de construire le service dans un conteneur DI sans planter au *boot* si ArangoDB est temporairement indisponible.

## Configuration — clés `ArangoConfig::*`

| Clé | Type | Description |
|---|---|---|
| `ArangoConfig::ENDPOINT` | `string` | URL du serveur (`tcp://host:port`). |
| `ArangoConfig::DATABASE` | `string` | Nom de la *database* cible. |
| `ArangoConfig::TYPE` | `string` | Schéma d'authentification (`Basic`). |
| `ArangoConfig::USER` | `string` | Utilisateur pour l'authentification. |
| `ArangoConfig::PASSWORD` | `string` | Mot de passe associé. |
| `ArangoConfig::CONNECTION` | `string` | `Close` (one-shot) ou `Keep-Alive` (réutilisée). |
| `ArangoConfig::TIMEOUT` | `int` | *Connect* et *request timeout* en secondes (même valeur appliquée aux deux). |
| `ArangoConfig::CREATE` | `bool` | Crée les collections manquantes lors d'une insertion (défaut `true`). |
| `ArangoConfig::RECONNECT` | `bool` | Tente une reconnexion si la connexion *keep-alive* a expiré (défaut `true`). |
| `ArangoConfig::DEBUG` | `bool` | Active le *logging* interne du driver legacy. |
| `ArangoConfig::BATCH_SIZE` | `int` | Taille de lot par défaut du *cursor* (défaut `10000`). |
| `ArangoConfig::MAX_RUNTIME` | `float` | Durée maximale d'une requête en secondes (`null` = pas de limite). |

## Instanciation via le conteneur DI

En production, `ArangoDB` est presque toujours enregistré comme service dans un conteneur PSR-11. Convention typique : un fichier de définition par *database* sous `api/definitions/@arango/`.

```php
// api/definitions/@arango/databases.php
use DI\Container ;
use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\ArangoConfig ;
use Psr\Log\LoggerInterface ;

return
[
    Databases::ARANGO => fn( Container $container ) => new ArangoDB
    (
        $container->get( 'config' )[ 'arango' ][ 'main' ] ,
        $container->get( LoggerInterface::class )
    ) ,
] ;
```

Du côté consommateur, le service est résolu par identifiant — les modèles `Documents` et `Edges` reçoivent le conteneur au constructeur et résolvent la *database* via la clé `AQL::DATABASE` :

```php
new Documents( $container ,
[
    AQL::COLLECTION => 'users' ,
    AQL::DATABASE   => Databases::ARANGO , // identifiant du service
    // ...
]) ;
```

## Exécuter une requête AQL brute

Trois temps : préparer les données, exécuter, lire le résultat.

```php
$db
    ->prepare
    ([
        'query'    => 'FOR u IN users FILTER u.active == @active RETURN u' ,
        'bindVars' => [ 'active' => true ] ,
    ])
    ->execute() ;

$users = $db->getDocuments() ; // array<object>
```

La méthode `prepare()` applique la `BATCH_SIZE` et le `MAX_RUNTIME` configurés. La méthode `execute()` crée un nouveau `Statement` et retourne `$this`, ce qui autorise le *chaining*.

### Récupérer le résultat

| Méthode | Retour | Usage |
|---|---|---|
| `getDocuments()` | `array` | Liste complète des documents. |
| `getFirstResult()` | `mixed` | Premier document, ou `null` si vide. |
| `getObject()` | `?object` | Premier document forcé en `object`. |
| `getResult()` | `?array` | Résultat brut depuis le *cursor* (peut être `null`). |
| `streamDocuments()` | `Generator` | Itération paresseuse, document par document. |

`streamDocuments()` est à privilégier dès qu'on suspecte un volume important : le *cursor* est consommé progressivement et le `$data` interne est réinitialisé à la fin de l'itération.

```php
foreach ( $db->streamDocuments() as $user )
{
    handle( $user ) ;
}
```

### Hydratation par schéma

Les quatre méthodes `getDocuments()`, `getFirstResult()`, `getObject()` et `streamDocuments()` acceptent un paramètre optionnel `$schema` de type `Closure | SchemaResolver | string | null` :

- `null` : le document est retourné sous forme d'`object` brut (cast `(object)` si le *driver* retourne un *array*).
- `string` (nom de classe) : si la classe étend `org\schema\Thing`, on appelle `new $class( $document )`. Sinon, hydratation reflective via `hydrate()`.
- `Closure` : appelée avec le document brut ; doit retourner soit un nom de classe (puis hydratation), soit le document final.
- `SchemaResolver` : implémentation polymorphe (utile pour discriminer la classe à partir d'un champ du document, par exemple `@type`).

```php
$users = $db->getDocuments( User::class ) ;        // array<User>
$first = $db->getFirstResult( fn( $d ) =>          // dispatch dynamique
    $d['type'] === 'admin' ? Admin::class : User::class ) ;
```

### Métadonnées du *cursor*

Après `execute()`, trois *getters* exposent les métadonnées :

- `getCursor()` — accès direct au [`Cursor`](../../src/oihana/arango/client/Cursor.php) sous-jacent.
- `getFoundRows()` — *total count* (équivalent à `FULL COUNT` AQL). Requiert d'avoir préparé la requête avec `fullCount: true`.
- `getExtra()` — métadonnées additionnelles renvoyées par le serveur (statistiques, *warnings*, *plan*).

## Gérer les collections

`ArangoDB` consomme [`CollectionManagementTrait`](../../src/oihana/arango/db/traits/CollectionManagementTrait.php), qui expose six méthodes idempotentes pour les collections :

| Méthode | Description |
|---|---|
| `collectionCreate( $name , $options )` | Crée la collection si elle n'existe pas. Retourne `true` si créée, `false` sinon. |
| `collectionDrop( $name )` | Supprime la collection si elle existe. |
| `collectionExists( $name )` | `true` si la collection existe. |
| `collectionRename( $oldName , $newName )` | Renomme. |
| `collectionTruncate( $name )` | Vide sans supprimer. |

Le paramètre `$options` de `collectionCreate()` accepte les clés du driver ArangoDB : `type` (2 = document, 3 = edge), `waitForSync`, `keyOptions`, `numberOfShards`, `replicationFactor`, `shardKeys`, `schema`, etc. Voir la PHPDoc du trait pour la liste complète.

```php
$db->collectionCreate( 'users' ) ;
$db->collectionCreate( 'user_has_roles' , [ 'type' => 3 ] ) ; // edge collection
```

## Gérer les index

Quatre méthodes pour les index, exposées par le même trait :

| Méthode | Description |
|---|---|
| `createIndex( $collection , $options )` | Crée un index. Accepte un tableau brut ou une instance d'`IndexOptions` (sérialisée automatiquement). |
| `dropIndex( $collection , $indexHandle )` | Supprime un index par son *handle*. |
| `getIndex( $collection , $indexId )` | Renvoie la définition d'un index. |
| `getIndexes( $collection )` | Liste tous les index d'une collection. |

```php
use oihana\arango\db\options\indexes\PersistentIndexOptions ;

$index         = new PersistentIndexOptions() ;
$index->fields = [ 'email' ] ;
$index->unique = true        ;

$db->createIndex( 'users' , $index ) ;
```

Le catalogue complet des classes `*IndexOptions` (`Persistent`, `TTL`, `Geo`, `MDI`, `Vector`) sera détaillé sur la page [Indexes et gestion des collections](indexes.md).

## Logger

Le second argument du constructeur accepte un `Psr\Log\LoggerInterface`. Toutes les erreurs réseau, les exceptions ArangoDB et les avertissements d'opérations sur les index sont écrits via ce *logger* — c'est le canal d'observabilité du framework. Si on passe `null`, les erreurs sont silencieusement avalées.

## Voir aussi

- [Helpers AQL `db/helpers/`](db-helpers.md) — construire des expressions AQL composables sans `sprintf`.
- [Bind variables `db/binds/`](db-binds.md) — injecter des valeurs en toute sécurité.
- [Modèles `Documents` et `Edges`](models.md) — couche haut-niveau qui consomme `ArangoDB` pour exposer le CRUD complet.
- [Glossaire](glossary.md) — termes du framework.
