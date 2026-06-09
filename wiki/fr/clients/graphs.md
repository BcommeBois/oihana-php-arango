# Graphes

ArangoDB est véritablement multi-modèle. On peut s'en servir comme stockage documentaire avec `Collection`, mais la technologie révèle vraiment son intérêt quand on traite les documents comme des **sommets** reliés par des **arêtes** — un graphe.

Deux manières de modéliser les arêtes :

| Approche | C'est quoi | Quand l'utiliser |
|---|---|---|
| **`EdgeCollection` brute** ([documents.md](documents.md#edges--ce-qui-change)) | Une collection dont les documents portent `_from`/`_to`. Le serveur ne valide pas quelles collections ces pointeurs peuvent référencer. | Scripts rapides, un seul type d'arête, vous faites confiance à vos données. |
| **Graphe nommé** (cette page) | Un `Graph` déclare des **edge definitions** qui contraignent quelles collections de sommets chaque type d'arête peut relier. Le serveur valide chaque insertion. | Graphes multi-types, type safety, intégrité multi-tenant, traversals AQL *gharial*. |

L'approche graphe nommé est ce que la doc ArangoDB appelle [*gharial*](https://docs.arangodb.com/stable/graphs/general-graphs/) — l'API HTTP derrière `Graph` est `/_api/gharial`.

## Définir un graphe

Un graphe est décrit par un ou plusieurs value objects `EdgeDefinition`.

```php
use oihana\arango\clients\graph\EdgeDefinition ;

$employs = new EdgeDefinition(
    collection : 'employs' ,         // nom de la collection d'arêtes
    from       : [ 'companies' ] ,    // collections sources autorisées
    to         : [ 'people' ] ,       // collections cibles autorisées
) ;

$graph = $db->createGraph( 'workplaces' , [ $employs ] ) ;
```

`createGraph()` crée le graphe, la collection d'arêtes et les collections de sommets si elles n'existent pas, puis renvoie un handle `Graph`.

Vous pouvez aussi construire le handle d'abord et l'inspecter avant de créer :

```php
$graph = $db->graph( 'workplaces' ) ;   // paresseux, pas de HTTP

if ( ! $graph->exists() )
{
    $graph->create( [ $employs ] ) ;
}
```

`createGraph()` accepte un troisième argument `$options` transmis au serveur : `orphanCollections`, `numberOfShards`, `replicationFactor`, `writeConcern`, `waitForSync`, plus des leviers Enterprise (`isSmart`, `smartGraphAttribute`, `isDisjoint`, `satellites`).

## Gérer les edge definitions

```php
// Ajouter un nouveau type d'arête à un graphe existant.
$friendship = new EdgeDefinition( 'knows' , [ 'people' ] , [ 'people' ] ) ;
$graph->addEdgeDefinition( $friendship ) ;

// Remplacer une définition existante (par exemple élargir les cibles).
$graph->replaceEdgeDefinition(
    new EdgeDefinition( 'employs' , [ 'companies' ] , [ 'people' , 'contractors' ] ) ,
) ;

// Retirer. Optionnellement, supprime la collection sous-jacente.
$graph->removeEdgeDefinition( 'employs' , dropCollection: true ) ;
```

## Collections de sommets et d'arêtes

Une fois le graphe créé, vous ne manipulez plus ses collections directement avec `Database::collection()` — vous passez par le graphe pour que le serveur puisse appliquer les contraintes.

```php
$companies = $graph->vertexCollection( 'companies' ) ;    // factory, pas de HTTP
$people    = $graph->vertexCollection( 'people' ) ;
$employs   = $graph->edgeCollection( 'employs' ) ;
```

`vertexCollections()` et `edgeCollections()` (sans suffixe `Collection` dans le nom de méthode) renvoient les noms de toutes les collections que ce graphe référence. `orphanCollections()` liste les collections de sommets enregistrées mais pas encore dans aucune edge definition — utile pendant la construction du modèle.

## CRUD de sommets

`GraphVertexCollection` expose la même forme CRUD que `Collection` (renvoie des objets `Document` immuables), routé via `/_api/gharial/{graph}/vertex/{collection}` :

```php
$alice = $people->insert(
    [ '_key' => 'alice' , 'name' => 'Alice' ] ,
    [ 'returnNew' => true ] ,
) ;

$alice = $people->document( 'alice' ) ;
$people->documentExists( 'alice' ) ;
$people->update ( 'alice' , [ 'age' => 30 ] ) ;
$people->replace( 'alice' , [ 'name' => 'Alice Doe' ] ) ;
$people->remove ( 'alice' ) ;
```

Options identiques à `Collection` : `returnNew`, `returnOld`, `waitForSync`, `keepNull`, `rev`.

## CRUD d'arêtes

`GraphEdgeCollection` fonctionne de la même façon, mais renvoie des objets `Edge` (sous-classe de `Document` avec accesseurs `getFrom()` et `getTo()`) et **valide `_from`/`_to` contre l'edge definition** :

```php
$edge = $employs->insert(
    [
        '_from' => 'companies/acme' ,
        '_to'   => 'people/alice' ,
        'since' => '2024-01-01' ,
    ] ,
    [ 'returnNew' => true ] ,
) ;

echo $edge->getFrom() ;   // 'companies/acme'
echo $edge->getTo() ;     // 'people/alice'

$employs->update( $edge->getKey() , [ 'since' => '2024-06-01' ] ) ;
$employs->remove( $edge->getKey() ) ;
```

Si vous tentez d'insérer une arête dont `_from` pointe vers une collection absente de l'edge definition, le serveur refuse. C'est tout l'intérêt de `Graph` par rapport à `EdgeCollection` brute.

## Traversal — AQL avec `GRAPH`

Il n'existe **pas de méthode `traverse()`** sur `Graph` ni sur `Database`. Les traversals s'écrivent en AQL, qui connaît les graphes nativement :

```php
use function oihana\arango\clients\aql\helpers\aql ;

$cursor = $db->query( aql(
    'FOR v, e, p IN 1..3 OUTBOUND ? GRAPH ? RETURN { vertex: v, edge: e, path: p }' ,
    'people/alice' ,
    'workplaces' ,
) ) ;

foreach ( $cursor as $hop )
{
    print_r( $hop ) ;
}
```

Mots-clés de direction : `OUTBOUND` (`_from → _to`), `INBOUND` (`_to → _from`), `ANY` (les deux). L'intervalle `1..3` est la profondeur — `1..3` signifie « entre un et trois sauts ».

La clause `GRAPH 'name'` indique au moteur de respecter les edge definitions du graphe. Vous pouvez aussi traverser des collections d'arêtes brutes avec `IN ... edges`, mais vous perdez la garantie de type safety.

> **Traversal anonyme et cluster.** Un traversal sur collections d'arêtes brutes (sans `GRAPH`) doit, **en cluster**, déclarer les collections de sommets atteignables via une clause `WITH` en tête de requête, faute de quoi le moteur risque un *deadlock*. Les méthodes de traversal des modèles `Edges` (`getOutboundVertices()`, `getInboundVertices()`, `getAnyVertices()`, `countVertices()`…) s'en chargent automatiquement à partir des modèles `_from` / `_to` ; voir [`aqlWith()`](../aql/aql-operations.md#aqlwith).

Pour tout ce qu'on peut exprimer dans un traversal — *pruning*, filtrage par attribut d'arête, plus courts chemins pondérés — voir la référence AQL officielle [Graph traversals](https://docs.arangodb.com/stable/aql/graphs/).

## Supprimer un graphe

```php
$graph->drop() ;                          // la définition du graphe uniquement
$graph->drop( dropCollections: true ) ;   // supprime aussi les collections — perte de données
```

## Aller plus loin

- [Requêtes AQL et Cursors](aql.md) — écrire les requêtes qui pilotent vos traversals.
- [Transactions](transactions.md) — garder atomique l'écriture de plusieurs arêtes.
- [Vue d'ensemble du client HTTP](README.md) — architecture et configuration.
