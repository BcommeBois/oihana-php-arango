# Collections et documents

Cette page couvre toute la surface pour travailler sur **une collection** via le client HTTP — toutes les méthodes CRUD de `Collection`, plus les opérations en batch et l'import en masse.

Elle suppose que vous avez lu [Démarrer](getting-started.md) et que vous avez déjà un `ArangoClient` opérationnel.

## Deux factories paresseuses

`Collection` et `EdgeCollection` sont tous deux des **handles paresseux** — les instancier ne fait aucun appel HTTP.

```php
$users   = $db->collection( 'users' ) ;          // collection de documents
$friends = $db->edgeCollection( 'friends' ) ;    // collection d'edges
```

Les edges sont des documents qui portent toujours des références `_from` et `_to` entre documents-sommets. Ils partagent toutes les méthodes CRUD ci-dessous ; seul `create()` diffère par son `type` par défaut (`EDGE` au lieu de `DOCUMENT`).

## Créer une collection

```php
$users->create() ;                                  // type document par défaut
$users->create([ 'waitForSync' => true ]) ;         // transmettre les options serveur
```

Options courantes transmises directement à ArangoDB :

| Option | Effet |
|---|---|
| `waitForSync` | Force le fsync à chaque écriture (plus lent, durable). |
| `keyOptions` | Configure la stratégie de génération de clé côté serveur. |
| `numberOfShards`, `replicationFactor` | Topologie cluster. |
| `schema` | Validateur JSON Schema. |

Voir la référence officielle [Create a collection](https://docs.arangodb.com/stable/develop/http-api/collections/#create-a-collection) pour la liste complète.

## L'objet `Document`

Toute lecture ou écriture qui renvoie un document unique produit un `Document` — un wrapper immuable.

| Méthode | Retourne |
|---|---|
| `getKey()` | `_key` (ou `null` si le document n'a jamais été persisté) |
| `getId()` | `_id` — handle qualifié (`collection/key`) |
| `getRev()` | `_rev` — token de révision géré par le serveur |
| `get( $field , $default = null )` | Valeur arbitraire d'un champ |
| `has( $field )` | `true` même si la valeur est `null` |
| `isNew()` | `true` si `_key` n'a pas été assigné |
| `toArray()` | Tableau associatif brut |

## Lire

```php
$doc    = $users->document( 'abc' ) ;          // lève HttpException(404) si absent
$exists = $users->documentExists( 'abc' ) ;    // bool, ne lève jamais sur 404
$count  = $users->count() ;                    // int
```

## Insérer

```php
$doc = $users->insert(
    [ 'name' => 'Alice' , 'email' => 'alice@example.com' ] ,
    [ 'returnNew' => true , 'waitForSync' => true ] ,
) ;
```

Options d'écriture courantes (transmises à ArangoDB) :

| Option | Effet |
|---|---|
| `returnNew` | Inclut le document inséré complet dans la réponse. |
| `returnOld` | (update / replace / remove) Inclut la version précédente. |
| `waitForSync` | Bloque jusqu'à ce que l'écriture soit sur disque. |
| `overwriteMode` | `ignore`, `replace`, `update`, `conflict` — sémantique d'upsert. |
| `silent` | Jette le corps de réponse — économise la bande passante sur les gros lots. |

Vous pouvez laisser le serveur choisir `_key`, ou le poser explicitement :

```php
$users->insert([ '_key' => 'alice' , 'name' => 'Alice' ]) ;
```

## Update vs Replace

```php
// PATCH — fusion ; les champs non touchés sont conservés.
$users->update( 'alice' , [ 'role' => 'admin' ] ) ;

// PUT — écrase tout sauf _key / _id / _rev.
$users->replace( 'alice' , [ 'name' => 'Alice Doe' ] ) ;
```

Les deux acceptent les mêmes options que `insert`. Posez `returnOld: true` pour récupérer la version d'avant.

## Supprimer

```php
$users->remove( 'alice' ) ;
$users->remove( 'alice' , [ 'returnOld' => true ] ) ;
```

## Truncate et drop

```php
$users->truncate() ;   // vide la collection, la conserve
$users->drop() ;       // supprime la collection côté serveur
```

## Opérations en batch

Quatre méthodes pour atteindre le serveur en **une** seule requête avec un corps multi-documents. Chacune renvoie un tableau d'instances `Document`, une par ligne d'entrée, dans l'ordre.

```php
$results = $users->saveAll(
    [
        [ 'name' => 'Alice' ] ,
        [ 'name' => 'Bob' ] ,
        [ 'name' => 'Carol' ] ,
    ] ,
    [ 'returnNew' => true ] ,
) ;

foreach ( $results as $doc )
{
    echo $doc->getKey() . PHP_EOL ;
}
```

| Méthode | Comportement |
|---|---|
| `saveAll( $documents , $options = [] )` | Insère N documents. |
| `updateAll( $patches , $options = [] )` | PATCH N — chaque patch doit inclure `_key` ou `_id`. |
| `replaceAll( $documents , $options = [] )` | PUT N — chaque doc doit inclure `_key` ou `_id`. |
| `removeAll( $selectors , $options = [] )` | Supprime N — les sélecteurs sont des clés (string) ou des tableaux `{ _key => ... }`. |

> **Les erreurs ligne par ligne ne lèvent pas.** Si l'un des 100 inserts est en conflit, vous récupérez tout de même un tableau de 100 `Document`s — la ligne en échec aura `error: true`, `errorNum` et `errorMessage`. Inspectez chaque résultat.

```php
foreach ( $users->saveAll( $rows ) as $i => $doc )
{
    if ( $doc->get( 'error' ) === true )
    {
        echo "ligne $i échouée : " . $doc->get( 'errorMessage' ) . PHP_EOL ;
    }
}
```

## Import en masse (JSON Lines)

Pour les gros chargements initiaux — des dizaines de milliers de lignes — préférez `import()`. Il utilise le endpoint `/_api/import` d'ArangoDB et est nettement plus rapide que `saveAll()` parce que le corps est streamé ligne par ligne.

```php
$result = $users->import(
    [
        [ 'name' => 'Alice' ] ,
        [ 'name' => 'Bob' ] ,
        // ... des milliers de plus
    ] ,
    [
        'onDuplicate' => 'update' ,   // ou 'replace' / 'ignore' / 'error'
        'details'     => true ,        // inclure le détail par erreur
    ] ,
) ;

echo $result->created ;       // int — insérés
echo $result->errors ;        // int — lignes rejetées
echo $result->updated ;       // int
echo $result->empty ;         // int — silencieusement ignorés (pas de _key, etc.)
echo $result->ignored ;       // int
```

| Option | Effet |
|---|---|
| `overwrite` | `true` tronque la collection cible avant. |
| `onDuplicate` | `error` (défaut), `update`, `replace`, `ignore`. |
| `details` | Inclut les messages d'erreur ligne-par-ligne dans `$result->details`. |
| `waitForSync` | Attend la persistance disque avant de rendre la main. |

Choisir le bon outil :

| Vous avez besoin de… | Utilisez |
|---|---|
| Le résultat complet de chaque ligne insérée (notamment avec `returnNew`) | `saveAll()` |
| Une gestion fine d'erreur ligne par ligne dans le code | `saveAll()` |
| Le débit maximum sur un chargement en masse one-shot | `import()` |
| Juste savoir « combien sont passées, combien ont échoué » | `import()` |

## Découverte

Trois helpers de commodité adossés à des requêtes AQL :

```php
$cursor = $users->byExample( [ 'role' => 'admin' ] , limit: 50 ) ;
$first  = $users->firstExample( [ 'email' => 'alice@example.com' ] ) ;   // ?Document
$all    = $users->all( limit: 100 ) ;
```

`byExample` et `all` renvoient un `Cursor`. `Cursor` est itérable et paresseux — il tire les batches depuis le serveur au fil de la consommation.

```php
foreach ( $users->all() as $doc )
{
    echo $doc->getKey() . PHP_EOL ;
}
```

Pour tout ce qui dépasse l'égalité simple, écrivez une vraie requête AQL via `$db->query( ... )` — couvert dans une page ultérieure.

## Edges — ce qui change

`EdgeCollection` étend `Collection`. Tout ce qui précède fonctionne. Une seule chose à retenir : chaque edge doit porter `_from` et `_to`.

```php
$friends = $db->edgeCollection( 'friends' ) ;
$friends->create() ;   // crée avec type: EDGE

$friends->insert([
    '_from' => 'users/alice' ,
    '_to'   => 'users/bob' ,
    'since' => '2020-01-01' ,
]) ;

// Requêter les edges par sommet :
$cursor = $friends->outEdges( 'users/alice' ) ;   // alice → ?
$cursor = $friends->inEdges ( 'users/bob' ) ;     // ? → bob
$cursor = $friends->edges   ( 'users/alice' ) ;   // dans les deux sens
```

## Gestion des erreurs

Toutes les méthodes d'écriture lèvent en cas de panne **transport** ou de rejet de la requête entière par le serveur :

```php
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\ConflictException ;
use oihana\arango\clients\exceptions\HttpException ;

try
{
    $users->update( 'alice' , [ 'role' => 'admin' ] ) ;
}
catch ( ConflictException $e )
{
    // Retryable — la révision a changé entre-temps.
}
catch ( HttpException $e )
{
    if ( $e->getCode() === 404 )
    {
        // Le document n'existe pas.
    }
}
catch ( ArangoException $e )
{
    // Autre problème serveur ou réseau.
}
```

Les erreurs ligne-par-ligne dans les méthodes batch (`saveAll`, etc.) **ne lèvent pas** — elles apparaissent dans le `Document` retourné avec `error: true`. Voir [Opérations en batch](#opérations-en-batch) ci-dessus.

## Aller plus loin

- [Démarrer](getting-started.md) — l'introduction pas-à-pas.
- [Vue d'ensemble du client HTTP](README.md) — architecture, authentification, résilience.
- [Indexes](../indexes.md) — accélérer vos requêtes.
- [Modèles `Documents` et `Edges`](../models.md) — la couche haut-niveau du framework au-dessus de ces primitives.
