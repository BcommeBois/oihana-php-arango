# Le client HTTP ArangoDB

`oihana/php-arango` expose deux couches d'API pour parler à ArangoDB :

| Couche | Dossier | Quoi |
|---|---|---|
| **Client HTTP bas niveau** | [`src/oihana/arango/clients/`](../../../src/oihana/arango/clients/) | Transport Guzzle, authentification, retry, gestion de cluster, requêtes brutes contre l'API REST d'arangod. |
| **Façade haut niveau** | [`src/oihana/arango/db/`](../../../src/oihana/arango/db/) ([`ArangoDB`](../getting-started/quickstart.md)) | Hydratation, exception wrapping, `prepare/execute`, helpers AQL — bâtie sur le client. |

Cette page documente la couche **client**. Pour le quickstart côté façade, voir [Quickstart `ArangoDB`](../getting-started/quickstart.md). Pour les modèles métier (`Documents`, `Edges`), voir [Modèles](../models.md).

> Le client est conçu **autonome** — pas de dépendance sur la couche `db/`, ni sur Slim, ni sur Symfony Console. On peut l'utiliser tel quel pour un script CLI, un *worker*, ou une suite de tests d'intégration. Il s'inspire de la lib JavaScript officielle [`arangojs`](https://github.com/arangodb/arangojs).

## Architecture

```
ArangoClient ─────► HttpTransport (Guzzle) ───► arangod
     │                    │
     │                    ├─► RetryPolicy   (1209 conflict, 3002 maintenance)
     │                    └─► HostRing      (round-robin failover cluster)
     │
     └──► Database (hub par database)
              ├─► Collection / EdgeCollection (CRUD + indexes + batch)
              ├─► Cursor             (Iterator + map/forEach/reduce/flatMap)
              ├─► Transaction        (streaming, withTransaction auto-commit/abort)
              ├─► Graph / GraphVertex/EdgeCollection
              ├─► Analyzer           (identity, text, norm, stem)
              └─► View               (arangosearch — SEARCH, PHRASE, BM25)
```

## Démarrage

```php
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\clients\enums\AuthType ;

$client = new ArangoClient( new ClientOptions(
    endpoints : [ 'tcp://127.0.0.1:8529' ] ,
    database  : 'my_database' ,
    authType  : AuthType::BASIC ,
    user      : 'root' ,
    password  : 'secret' ,
) ) ;

$db = $client->database() ;                          // factory Database
$users = $db->collection( 'users' ) ;                // factory Collection
$doc = $users->document( 'abc' ) ;                   // GET /_api/document/users/abc
$count = $users->count() ;                           // GET /_api/collection/users/count

// AQL
use oihana\arango\clients\aql\AqlQuery ;
use function oihana\arango\clients\aql\helpers\aql ;

$cursor = $db->query( aql(
    'FOR u IN @@coll FILTER u.active == @active RETURN u' ,
    bindVars: [ '@coll' => 'users' , 'active' => true ] ,
) ) ;

foreach ( $cursor as $user )
{
    echo $user[ 'name' ] . PHP_EOL ;
}
```

## `ArangoClient` — entry point

Une instance `ArangoClient` représente une connexion **à un cluster** (un ou plusieurs *endpoints*). Sa configuration est immutable une fois passée — elle est portée par un `ClientOptions` `readonly`.

| Méthode | Description |
|---|---|
| `database( ?string $name = null ) : Database` | Factory `Database` pour le nom donné (ou celui passé dans `ClientOptions::$database`). |
| `createDatabase( string $name ) : void` | `POST /_api/database`. |
| `dropDatabase( string $name ) : void` | `DELETE /_api/database/{name}`. |
| `listDatabases() : array` | `GET /_api/database`. |
| `version() : array` | `GET /_api/version`. |
| `time() : float` | `GET /_admin/time` — horloge serveur en secondes flottantes. |
| `availability( bool $graceful = true ) : string\|false` | `GET /_admin/server/availability` — retourne le mode serveur (`default` / `readonly`) ou `false`. |
| `login( string $user , string $password ) : string` | `POST /_open/auth` — récupère un JWT. Bascule automatiquement le transport en Bearer. |
| `useBearerAuth( ?string $token ) : void` | Force un token Bearer (ou revient en Basic avec `null`). |
| `useBasicAuth( string $user , string $password ) : void` | Force des creds Basic. |
| `request( string $method , string $path , …)` | Requête brute (à utiliser pour des endpoints non encore wrappés). |

## `Database` — hub

Toutes les opérations propres à une *database* passent par un `Database`. Une instance est obtenue via `$client->database( 'name' )`.

| Méthode | Quoi |
|---|---|
| `collection( string $name ) : Collection` | Factory document collection. |
| `edgeCollection( string $name ) : EdgeCollection` | Factory edge collection. |
| `collections( bool $includeSystem = false ) : array` | Liste typée. |
| `query( AqlQuery\|string $query ) : Cursor` | Exécute une requête AQL, retourne un `Cursor` lazy. |
| `explain( ... )` / `parse( ... )` | Diagnostic AQL (plan d'exécution + parsing). |
| `beginTransaction( ... ) : Transaction` / `transaction( string $id ) : Transaction` / `withTransaction( callable $fn , ... )` | Transactions *streaming* (multi-document). `withTransaction` gère le commit/abort automatiquement via `try / finally`. |
| `listTransactions() : array` | Transactions actives sur cette database. |
| `graph( string $name )` / `graphs()` / `listGraphs()` / `createGraph( ... )` | Gestion *gharial*. |
| `analyzer( string $name )` / `analyzers()` / `listAnalyzers()` / `createAnalyzer( ... )` | Analyzers ArangoSearch. |
| `view( string $name )` / `views()` / `listViews()` / `createView( ... )` | Views ArangoSearch. |
| `exists()` / `create()` / `drop()` | Lifecycle de la database. |

## Authentification

Trois modes pris en charge par `ClientOptions::$authType` :

- `AuthType::BASIC` — credentials passés en `Authorization: Basic …` sur chaque requête. Mode par défaut.
- `AuthType::JWT` (alias `BEARER`) — token passé en `Authorization: Bearer …`. Le token peut être obtenu via `$client->login( $user , $password )` qui le bascule automatiquement.
- **Auto-refresh sur 401**. Si une requête en Bearer reçoit un 401, le transport tente un seul `login` puis rejoue la requête. Le flag est porté par `HttpTransport` (et non par `ClientOptions`, qui reste *readonly*).

```php
// Démarrage en Basic, puis échange contre un JWT
$token = $client->login( 'root' , 'secret' ) ;
// Le client est maintenant en Bearer automatiquement.

// Revenir en Basic explicitement
$client->useBasicAuth( 'root' , 'secret' ) ;
```

## Résilience : retry et failover cluster

`ClientOptions::$endpoints` accepte **plusieurs URLs** — la classe [`HostRing`](https://github.com/BcommeBois/oihana-php-arango/blob/main/src/oihana/arango/clients/http/HostRing.php) sélectionne un hôte en *round-robin* et bascule sur le suivant en cas d'échec réseau.

`RetryPolicy` est invoquée pour les **codes d'erreur Arango** *safe-to-retry* :

- `1209` — `ERROR_ARANGO_CONFLICT` (write-write conflict, le moteur peut être re-tenté).
- `3002` — `ERROR_CLUSTER_AGENCY_*` / maintenance — typiquement transient pendant un *leader switch*.

```php
$client = new ArangoClient( new ClientOptions(
    endpoints : [ 'tcp://node-1:8529' , 'tcp://node-2:8529' , 'tcp://node-3:8529' ] ,
    database  : 'app' ,
    user      : 'root' ,
    password  : 'secret' ,
    reconnect : true ,        // garde le keep-alive sur reconnexion
) ) ;
```

## Lecture dirty (replicas)

Sur cluster, on peut autoriser la lecture sur replicas en activant le flag global `allowDirtyRead` — il injecte le header `x-arango-allow-dirty-read: true` sur **toutes** les requêtes :

```php
$client = new ArangoClient( new ClientOptions(
    endpoints       : [ ... ] ,
    database        : 'app' ,
    user            : 'root' ,
    password        : 'secret' ,
    allowDirtyRead  : true ,    // OPT-IN
) ) ;
```

Le serveur reste libre de servir la requête depuis un follower ; à utiliser uniquement pour des opérations de lecture tolérantes à un léger *lag* de réplication.

## Quand utiliser le client direct vs la façade

| Besoin | Choisir |
|---|---|
| Script CLI dédié, test d'intégration, *worker* | Client direct (`ArangoClient` + `Database`). |
| Application avec PSR-11 DI, modèles `Documents` réutilisables, signals before/after, exceptions wrappées en `oihana\arango\client\Exception` legacy | Façade `ArangoDB` ([Quickstart](../getting-started/quickstart.md)). |
| Une seule requête AQL ponctuelle dans une appli qui consomme déjà la façade | Récupérer le client via `$arangoDB->getClient()` (déconseillé sauf cas spéciaux — préférer `prepare/execute` côté façade). |

## Voir aussi

- [Quickstart `ArangoDB`](../getting-started/quickstart.md) — la façade haut niveau.
- [Modèles `Documents` et `Edges`](../models.md) — la couche métier.
- [Indexes](../indexes.md) — catalogue d'indexes typés.
- [Testing](../testing.md) — les deux commandes live `arango:test:clients` et `arango:test:facade`.
- [arangojs (lib officielle JS)](https://github.com/arangodb/arangojs) — référence architecturale.
