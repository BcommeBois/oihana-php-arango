# Le client HTTP ArangoDB

`oihana/php-arango` expose deux couches d'API pour parler à ArangoDB :

| Couche | Dossier | Quoi |
|---|---|---|
| **Client HTTP bas niveau** | [`src/oihana/arango/clients/`](../../../src/oihana/arango/clients/) | Transport Guzzle, authentification, retry, gestion de cluster, requêtes brutes contre l'API REST d'arangod. |
| **Façade haut niveau** | [`src/oihana/arango/db/`](../../../src/oihana/arango/db/) ([`ArangoDB`](../getting-started/quickstart.md)) | Hydratation, exception wrapping, `prepare/execute`, helpers AQL — bâtie sur le client. |

Cette page documente la couche **client**. Pour le quickstart côté façade, voir [Quickstart `ArangoDB`](../getting-started/quickstart.md). Pour les modèles métier (`Documents`, `Edges`), voir [Modèles](../models.md).

> Le client est conçu **autonome** — pas de dépendance sur la couche `db/`, ni sur Slim, ni sur Symfony Console. On peut l'utiliser tel quel pour un script CLI, un *worker*, ou une suite de tests d'intégration. Il s'inspire de la lib JavaScript officielle [`arangojs`](https://github.com/arangodb/arangojs).

## Apprendre le client pas à pas

Si vous n'avez jamais utilisé ArangoDB, lisez ces pages dans l'ordre. Chacune s'appuie sur la précédente.

| # | Page | Public |
|---|---|---|
| 1 | [Démarrer](getting-started.md) | **Débutants** — votre premier `ArangoClient`, votre premier document, en sept petites étapes. |
| 2 | [Collections et documents](documents.md) | Débutant → intermédiaire — CRUD complet, opérations en batch, import JSON-Lines en masse, edges. |
| 3 | [Requêtes AQL et Cursors](aql.md) | Intermédiaire — helper `aql()`, `AqlQuery`, bind variables, `Cursor` paresseux avec `map` / `forEach` / `reduce` / `flatMap`. |
| 4 | [Graphes](graphs.md) | Intermédiaire — graphes *gharial* nommés, `EdgeDefinition`, collections vertex/edge avec insertions type-safe, traversal AQL. |
| 5 | [Transactions](transactions.md) | Avancé — transactions streaming, `withTransaction()` auto-commit/abort, scoping de l'accès aux collections. |
| 6 | [Indexes](indexes.md) | Intermédiaire — sept classes d'index typées (`PersistentIndex`, `GeoIndex`, `TtlIndex`, `MDIIndex`, `VectorIndex`, `InvertedIndex`, `FulltextIndex`). |
| 7 | [ArangoSearch](arangosearch.md) | Avancé — analyzers et views pour la recherche full-text multi-collections avec `SEARCH` / `BM25` / `PHRASE`. |
| 8 | [Résilience et authentification](resilience.md) | Ops — modes d'auth et auto-refresh sur 401, politique de retry, failover multi-hôtes, timeouts, dirty reads. |

Le reste de cette page est une **référence** — diagramme d'architecture, exemple rapide, tables de méthodes pour `ArangoClient` et `Database`, quand utiliser le client vs la façade haut niveau.

## Architecture

```
ArangoClient ─────► HttpTransport (Guzzle) ───► arangod
     │                    │
     │                    ├─► RetryPolicy   (1200 conflict, 3002 maintenance)
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

## Exemple rapide

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

## Configuration et résilience

Les modes d'authentification (Basic, JWT avec auto-refresh sur 401), la politique de retry sur erreurs transitoires, le failover multi-hôtes, les timeouts, le keep-alive et les dirty reads sont tous couverts sur une page dédiée — voir [Résilience et authentification](resilience.md).

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
