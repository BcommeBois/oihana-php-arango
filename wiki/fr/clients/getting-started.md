# Démarrer avec le client HTTP

Cette page vous accompagne pour écrire votre premier script ArangoDB avec le **client bas niveau** — `ArangoClient`. À la fin, vous aurez un petit programme qui se connecte au serveur, crée une base, insère un document, le relit, le modifie, puis nettoie.

Elle suppose que vous n'avez **jamais** utilisé ArangoDB et ne nécessite aucune des autres pages de cette documentation.

## Prérequis

- ArangoDB 3.11+ tournant en local sur `tcp://127.0.0.1:8529`. Le plus rapide : `docker run -p 8529:8529 -e ARANGO_ROOT_PASSWORD=secret arangodb/arangodb:latest`.
- PHP 8.4+ disponible sur votre machine.
- Un projet où `composer require oihana/php-arango` a été lancé.

> Vous n'avez pas ArangoDB sous la main ? Vous pouvez aussi sauter aux [smoke tests](../testing.md) — la commande livrée `arango:test:clients` monte une base éphémère et exerce toutes les méthodes publiques.

## Le modèle mental en 30 secondes

ArangoDB stocke des **documents** — des objets JSON — à l'intérieur de **collections** — des paniers de documents. Chaque document a trois champs réservés gérés par le serveur :

| Champ | Posé par | Rôle |
|---|---|---|
| `_key` | client (ou serveur si omis) | Identifiant unique à l'intérieur de la collection, ex. `"123"`. |
| `_id` | serveur | Handle complet : `"users/123"`. |
| `_rev` | serveur | Chaîne de révision qui change à chaque écriture. Utilisé pour la concurrence optimiste. |

Vous ne posez vous-même que `_key` (et vous pouvez laisser le serveur le choisir). Les deux autres en découlent.

## Étape 1 — Se connecter

Créez un `ArangoClient`. Les seules entrées obligatoires sont le endpoint, le nom de la base, et les identifiants.

```php
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;

$client = new ArangoClient( new ClientOptions(
    endpoints : [ 'tcp://127.0.0.1:8529' ] ,
    database  : 'tutorial' ,
    user      : 'root' ,
    password  : 'secret' ,
) ) ;
```

Aucune requête HTTP n'est encore partie — `ArangoClient` est un handle configuré, pas une connexion active.

## Étape 2 — S'assurer que la base existe

La base `tutorial` n'existe pas encore. Demandez un handle, puis créez-la si nécessaire.

```php
$db = $client->database( 'tutorial' ) ;   // factory, pas de HTTP

if ( ! $db->exists() )
{
    $db->create() ;
}
```

`exists()` envoie une seule requête légère. `create()` envoie un `POST /_api/database`.

## Étape 3 — S'assurer que la collection existe

Même schéma.

```php
$users = $db->collection( 'users' ) ;     // factory, pas de HTTP

if ( ! $users->exists() )
{
    $users->create() ;
}
```

Si vous préférez voir les collections apparaître à la demande, mettez `create: true` dans `ClientOptions` (c'est la valeur par défaut) — le client créera automatiquement les collections manquantes au premier insert.

## Étape 4 — Insérer votre premier document

```php
$doc = $users->insert(
    [ 'name' => 'Marc' , 'role' => 'admin' ] ,
    [ 'returnNew' => true ] ,
) ;

echo $doc->getKey() ;        // ex. '12345' — assigné par le serveur
echo $doc->getId()  ;        // 'users/12345'
echo $doc->get( 'name' ) ;   // 'Marc' (grâce à returnNew)
```

`insert()` renvoie un `Document` — un *wrapper* immuable autour de la réponse serveur. Par défaut le serveur ne renvoie que `{ _key, _id, _rev }`. Avec `returnNew: true`, le document complet inséré est inclus et accessible via `$doc->get( ... )`.

## Étape 5 — Le relire

```php
$fetched = $users->document( $doc->getKey() ) ;

echo $fetched->get( 'name' ) ;   // 'Marc'
echo $fetched->getRev() ;        // un token de révision
```

## Étape 6 — Update ou Replace

Update — sémantique PATCH, fusionne les champs passés avec le document existant :

```php
$updated = $users->update(
    $doc->getKey() ,
    [ 'role' => 'superadmin' ] ,
    [ 'returnNew' => true ] ,
) ;

echo $updated->get( 'name' ) ;   // 'Marc'         (conservé)
echo $updated->get( 'role' ) ;   // 'superadmin'   (modifié)
```

Replace — sémantique PUT, écrase tout sauf `_key`/`_id`/`_rev` et écrit ce que vous passez :

```php
$replaced = $users->replace(
    $doc->getKey() ,
    [ 'name' => 'Marc Alcaraz' ] ,
) ;
// 'role' a disparu.
```

## Étape 7 — Nettoyer

```php
$users->remove( $doc->getKey() ) ;
// $users->truncate() ;    // viderait toute la collection
// $users->drop() ;        // supprimerait la collection côté serveur
// $db->drop() ;           // supprimerait toute la base
```

## Ce que vous venez d'apprendre

- Un `ArangoClient` est une configuration, pas une connexion — l'instancier ne touche pas le réseau.
- Les instances `Database` et `Collection` sont aussi des factories paresseuses. Le premier appel HTTP a généralement lieu sur `exists()`/`create()`/`insert()`.
- Les documents sont rendus sous forme d'objets `Document` immuables, avec `getKey()`, `getId()`, `getRev()` et un accesseur générique `get( $field , $default = null )`.
- Les champs réservés (`_key`, `_id`, `_rev`) sont gérés par le serveur, sauf si vous posez `_key` explicitement.

## Quand ça se passe mal

Toute la bibliothèque lève des sous-classes de `ArangoException` :

| Exception | Quand | Retryable ? |
|---|---|---|
| `HttpException` | 4xx/5xx générique — y compris `404` (document absent) | Non |
| `ConflictException` | `409` — conflit d'écriture sur la même révision | **Oui** |
| `MaintenanceException` | Bascule de leader cluster, maintenance d'agency | Oui |
| `NetworkException` | Panne de transport (DNS, timeout, socket) | Peut-être — dépend de l'opération |

Attrapez la classe de base si vous ne voulez pas distinguer, ou affinez :

```php
use oihana\arango\clients\exceptions\HttpException ;

try
{
    $users->document( 'no-such-key' ) ;
}
catch ( HttpException $e )
{
    if ( $e->getCode() === 404 )
    {
        // Non trouvé.
    }
}
```

## Aller plus loin

- [Collections et documents](documents.md) — toute la surface CRUD, les opérations en batch, l'import en masse.
- [Vue d'ensemble du client HTTP](README.md) — architecture, authentification, retry, failover cluster.
- [Tips et pièges](../tips.md) — règles d'or à respecter en production.
