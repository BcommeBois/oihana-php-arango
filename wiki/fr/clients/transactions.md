# Transactions

ArangoDB supporte les **streaming transactions** : vous déclarez d'emblée quelles collections seront lues ou écrites, le serveur vous remet un **id de transaction**, et vous exécutez plusieurs opérations sous cet id jusqu'au commit ou à l'abort. Toutes les écritures stagées partent ensemble — atomiquement — ou sont jetées.

Cette page couvre la surface client autour de ces transactions.

## Le modèle

1. Ouvrir une transaction en listant le pattern d'accès — lecture, écriture, exclusif — et laisser le serveur réserver les verrous.
2. Exécuter autant d'opérations CRUD ou AQL que nécessaire. Le client tague chaque requête avec l'id de transaction automatiquement (header `x-arango-trx-id`).
3. Appeler `commit()` pour appliquer toutes les écritures, ou `abort()` pour les jeter. Le serveur abortera lui-même la transaction si elle reste inactive plus longtemps que `lockTimeout`.

Un handle de transaction est **à usage unique** : une fois committed ou aborted, il ne peut plus servir.

> La référence officielle est [Stream transactions](https://docs.arangodb.com/stable/develop/transactions/stream-transactions/) — à mettre en favori pour les cas limites.

## Le helper auto-commit — préféré

Dans 95 % des cas, utilisez `withTransaction()`. Il ouvre la transaction, appelle votre callback, puis commit en cas de succès ou abort en cas d'exception.

```php
$result = $db->withTransaction(
    function( Transaction $trx ) use ( $db )
    {
        $db->collection( 'users'  )->insert([ 'name' => 'Alice' ]) ;
        $db->collection( 'audits' )->insert([ 'action' => 'created' ]) ;

        return 'ok' ;
    } ,
    write : [ 'users' , 'audits' ] ,
) ;

echo $result ;   // 'ok' — ce que le callback a renvoyé
```

À l'intérieur du callback vous n'avez pas besoin d'appeler `$trx->commit()` — le helper gère le cycle de vie. Lever une exception depuis le callback déclenche `abort()` (best-effort) puis la relève.

Vous pouvez combiner les modes d'accès :

```php
$db->withTransaction(
    $callback ,
    write     : [ 'orders' ] ,
    read      : [ 'inventory' ] ,
    exclusive : [ 'sequence' ] ,
    options   : [
        'waitForSync'        => true ,
        'lockTimeout'        => 60 ,   // secondes
        'maxTransactionSize' => 1_000_000 ,
    ] ,
) ;
```

Au moins l'un de `write` / `read` / `exclusive` doit être non vide — le serveur refuse les transactions vides.

## Contrôle manuel — `beginTransaction`

Quand vous devez tenir une transaction au-delà d'une boucle d'événements, ou committer conditionnellement :

```php
$trx = $db->beginTransaction(
    write : [ 'users' ] ,
    read  : [ 'roles' ] ,
) ;

try
{
    $trx->step( function() use ( $db )
    {
        $db->collection( 'users' )->insert([ 'name' => 'Bob' ]) ;
    } ) ;

    if ( $someCondition )
    {
        $trx->commit() ;
    }
    else
    {
        $trx->abort() ;
    }
}
catch ( \Throwable $e )
{
    $trx->abort() ;
    throw $e ;
}
```

`step( callable $cb )` est le seul moyen de faire participer des appels `Collection`/`Database` ordinaires à la transaction. À l'intérieur du callback, l'id de transaction est installé dans le transport — chaque requête porte automatiquement le bon header, vous n'avez pas à le câbler manuellement.

En dehors de `step()`, les appels classiques s'exécutent **sans** l'id de transaction et visent la base directement.

## Le handle `Transaction`

| Propriété / méthode | Ce qu'elle donne |
|---|---|
| `$trx->database` | Le `Database` parent. |
| `$trx->id` | Id assigné par le serveur (URL-safe). |
| `$trx->status() : string` | État courant — `RUNNING`, `COMMITTED`, ou `ABORTED`. Tape le serveur. |
| `$trx->exists() : bool` | `true` si le serveur connaît encore la transaction. Traite 404 comme `false`. |
| `$trx->commit() : string` | Committe et renvoie l'état terminal. Lève `ArangoException` en cas d'échec. |
| `$trx->abort() : string` | Abort et renvoie l'état terminal. |
| `$trx->step( callable $cb ) : mixed` | Exécute `$cb` avec l'id de transaction actif. La valeur de retour est propagée. |

L'enum `TransactionStatus` liste les trois états possibles :

```php
use oihana\arango\clients\transaction\enums\TransactionStatus ;

TransactionStatus::RUNNING ;
TransactionStatus::COMMITTED ;
TransactionStatus::ABORTED ;
```

## Inspecter et récupérer une transaction

Deux helpers sur `Database` :

```php
// Liste les transactions actives (admin / diagnostic).
$active = $db->listTransactions() ;
foreach ( $active as $entry )
{
    echo $entry[ 'id' ] . ' — ' . $entry[ 'state' ] . PHP_EOL ;
}

// Recolle un handle sur un id connu sans toucher le serveur.
$trx = $db->transaction( 'abc-123' ) ;

if ( $trx->exists() )
{
    $trx->commit() ;
}
```

Cas d'usage : si un worker plante au milieu d'une transaction, vous pouvez confier l'id à un job de nettoyage qui l'abort proprement. Le serveur fera timeout tout seul, mais un abort explicite libère les verrous instantanément.

## Référence des options

`$options` accepté par `beginTransaction()` et `withTransaction()` :

| Clé | Type | Effet |
|---|---|---|
| `waitForSync` | `bool` | Attendre la persistance disque au commit. |
| `allowImplicit` | `bool` | Autorise la lecture sur des collections non déclarées dans `read` (défaut `false`). |
| `lockTimeout` | `int` | Secondes avant que le serveur abort la transaction pour inactivité. |
| `maxTransactionSize` | `int` | Taille maximale (octets) des écritures stagées. Garde-fou utile. |
| `skipFastLockRound` | `bool` | Saute le pre-check rapide de verrouillage en cluster (avancé). |
| `allowDirtyRead` | `bool` | Permet les dirty reads à l'intérieur de la transaction. |

## Ce qu'on ne peut pas faire dans une transaction

- Mélanger des étapes provenant de plusieurs instances de `Database`.
- Ouvrir une autre transaction à l'intérieur d'un callback `step()` (le nesting n'est pas supporté).
- Réutiliser le même handle `Transaction` après `commit()` ou `abort()` — il en faut un neuf.
- Exécuter des requêtes AQL qui touchent des collections non déclarées, sauf si `allowImplicit: true`.

## Aller plus loin

- [Requêtes AQL et Cursors](aql.md) — exécuter de l'AQL à l'intérieur d'une transaction.
- [Graphes](graphs.md) — l'écriture multi-arêtes est un cas naturel pour les transactions.
- [Vue d'ensemble du client HTTP](README.md) — architecture et configuration.
