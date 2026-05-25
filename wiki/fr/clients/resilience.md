# Résilience et authentification

Cette page couvre tout ce que le client fait pour continuer à fonctionner quand le réseau — ou le cluster — devient bruyant : modes d'authentification et auto-refresh sur 401, retry sur erreurs transitoires, failover multi-hôtes, timeouts, keep-alive, dirty reads.

Si vous débarquez : les valeurs par défaut sont raisonnables pour du dev local. Lisez cette page au passage en production.

## Authentification

`ClientOptions::$authType` accepte deux valeurs de l'enum `AuthType` :

| Mode | Ce qui est envoyé |
|---|---|
| `AuthType::BASIC` (défaut) | `Authorization: Basic base64(user:password)` à chaque requête. |
| `AuthType::JWT` (alias `BEARER`) | `Authorization: Bearer <jwt>` à chaque requête. |

Mise en place directe :

```php
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\clients\enums\AuthType ;

$client = new ArangoClient( new ClientOptions(
    endpoints : [ 'http://127.0.0.1:8529' ] ,
    database  : 'app' ,
    authType  : AuthType::JWT ,
    user      : 'root' ,
    password  : 'secret' ,
) ) ;

$client->login( 'root' , 'secret' ) ;   // obtient un JWT et le stocke pour réutilisation
```

### Auto-refresh sur 401

Quand le transport voit un `401` sur une requête et que les **identifiants Basic sont encore stockés**, il :

1. Appelle `login(user, password)` sur `/_open/auth` pour obtenir un JWT frais.
2. Met à jour le token en mémoire.
3. **Rejoue la requête initiale une fois** avec le nouveau token.

Cela ne compte pas dans le budget de retry. Le refresh s'active au plus une fois par requête — la ré-entrée est bloquée par un flag interne, donc une auth cassée ne tourne pas en boucle.

Si le refresh échoue à son tour, le 401 d'origine est relevé pour que l'appelant voie la cause racine.

### Changer d'auth à l'exécution

```php
$client->useBasicAuth( 'admin' , 'newsecret' ) ;   // passe en Basic ; jette le JWT
$client->useBearerAuth( $jwt ) ;                    // passe en JWT explicitement
$client->useBearerAuth( null ) ;                    // revient en Basic
$client->login( 'admin' , 'newsecret' ) ;           // Basic → JWT en un appel
```

Les identifiants Basic restent stockés même quand vous basculez en Bearer — c'est ce qui maintient l'auto-refresh sur 401.

## Politique de retry

Les erreurs transitoires sont retentées automatiquement. La `RetryPolicy` du transport décide ce qui compte comme « transitoire » :

| Source | Détail |
|---|---|
| ArangoDB `errorNum` **1200** (`ARANGO_CONFLICT`) | Conflit d'écriture sur la même révision. HTTP 409. |
| ArangoDB `errorNum` **3002** (`CLUSTER_BACKEND_UNAVAILABLE`) | DBServer temporairement indisponible pendant une maintenance cluster. HTTP 503. |
| Erreurs réseau | `ConnectException`, `GuzzleException` de transport (DNS, TCP refusé, timeouts). |

**Budget de retry** — défauts :

- 3 tentatives totales (1 initiale + 2 retries).
- Backoff exponentiel : `100 ms × 2^(n-1)`, plafonné à `5 000 ms`. Les délais sont donc 100 ms, 200 ms, 400 ms, …
- L'auto-refresh sur 401 est **séparé** de ce budget.

Quand toutes les tentatives sont épuisées, la dernière exception remonte.

## Failover multi-hôtes

`ClientOptions::$endpoints` accepte une liste. Le `HostRing` tourne dessus.

```php
$client = new ArangoClient( new ClientOptions(
    endpoints : [
        'http://node-1:8529' ,
        'http://node-2:8529' ,
        'http://node-3:8529' ,
    ] ,
    database  : 'app' ,
    user      : 'root' ,
    password  : 'secret' ,
) ) ;
```

Quand une requête échoue avec une erreur transport retryable, le transport appelle `HostRing::next()` pour avancer le curseur avant de réessayer. Le ring reste sur l'hôte qui faillit si un retry réussit — le curseur ne bouge que quand quelque chose casse réellement. L'état persiste pour la durée de vie de l'instance `ArangoClient`.

> Les schémas d'URL legacy sont normalisés : `tcp://` → `http://`, `ssl://`/`tls://` → `https://`. Mixer les protocoles dans un même ring fonctionne, mais c'est plus clair de les uniformiser.

## Dirty reads (réplicas)

En cluster, vous pouvez laisser les lectures être servies par les followers — au prix de données légèrement périmées.

```php
$client = new ArangoClient( new ClientOptions(
    endpoints      : [ ... ] ,
    database       : 'app' ,
    user           : 'root' ,
    password       : 'secret' ,
    allowDirtyRead : true ,    // OPT-IN
) ) ;
```

Activé, le header `x-arango-allow-dirty-read: true` est tamponné sur **chaque** requête sortante, pas seulement les GET. Les déploiements mono-serveur l'ignorent silencieusement. À utiliser uniquement pour des lectures qui tolèrent le retard de réplication.

## Timeouts

Quatre leviers, avec des portées différentes :

| Option | Mappé à | Défaut | Portée |
|---|---|---|---|
| `connectTimeout` | Guzzle `connect_timeout` | 5 s | Handshake TCP/TLS. |
| `requestTimeout` | Guzzle `timeout` | 30 s | Requête complète (connect + read). |
| `timeout` | Fallback dans `requestTimeout` si ce dernier est null. | 30 s | Levier legacy unique — gardez les deux à la même valeur si vous les posez. |
| `maxRuntime` | Paramètre de query string au serveur | `null` (illimité) | Budget d'exécution par requête, côté serveur, en secondes. |

`requestTimeout` est celui qu'on ajuste le plus souvent. `maxRuntime` est appliqué par le serveur et utile pour les requêtes AQL susceptibles de scanner beaucoup.

## Mode de connexion et keep-alive

```php
use oihana\arango\clients\enums\ConnectionMode ;

new ClientOptions(
    // ...
    connection : ConnectionMode::KEEP_ALIVE ,   // défaut — réutilise TCP
    reconnect  : true ,                          // (défaut) prêt à reconnecter sur socket périmé
) ;
```

`ConnectionMode::CLOSE` ouvre et ferme un TCP frais à chaque requête — utile uniquement pour des scripts très courts où vous ne voulez aucun pooling. Sinon, laissez `KEEP_ALIVE`.

`reconnect: true` permet à Guzzle de rétablir silencieusement une connexion quand le serveur a abandonné une socket keep-alive de son côté. Le désactiver fait remonter la socket cassée comme une exception — rarement ce qu'on veut.

## Recette — cluster de production 3 nœuds

```php
use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\clients\enums\AuthType ;
use oihana\arango\clients\enums\ConnectionMode ;

$client = new ArangoClient( new ClientOptions(
    endpoints      : [
        'https://coord-1.example.com:8529' ,
        'https://coord-2.example.com:8529' ,
        'https://coord-3.example.com:8529' ,
    ] ,
    database       : 'app' ,
    authType       : AuthType::JWT ,
    user           : 'service' ,         // conservé pour l'auto-refresh sur 401
    password       : $secret ,
    connectTimeout : 5 ,
    requestTimeout : 30 ,
    connection     : ConnectionMode::KEEP_ALIVE ,
    reconnect      : true ,
    allowDirtyRead : false ,             // cohérence forte sur toute l'app
) ) ;

$client->login( 'service' , $secret ) ;   // frappe le premier JWT
```

À partir de là :
- Les requêtes round-robin transparenment sur les trois coordinateurs en cas d'échec transitoire.
- Un 401 (JWT expiré) déclenche un re-login silencieux puis un replay.
- Les conflits ArangoDB (1200) et hoquets de maintenance (3002) sont retentés jusqu'à deux fois avec backoff exponentiel.

## Aller plus loin

- [Vue d'ensemble du client HTTP](README.md) — diagramme d'architecture et exemple rapide.
- [Démarrer](getting-started.md) — l'intro en sept étapes pour les nouveaux lecteurs.
- [Transactions](transactions.md) — `ConflictException::isSafeToRetry()` est particulièrement pertinent dans une transaction.
