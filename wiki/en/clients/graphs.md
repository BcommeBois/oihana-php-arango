# Graphs

ArangoDB is genuinely multi-model. You can use it as a document store with `Collection`, but the technology really pays off when you treat documents as **vertices** linked by **edges** — a graph.

There are two ways to model edges:

| Approach | What it is | When to use |
|---|---|---|
| **Raw `EdgeCollection`** ([documents.md](documents.md#edges--what-changes)) | A collection whose documents carry `_from`/`_to` pointers. The server doesn't validate which collections those pointers may reference. | Quick scripts, single edge type, you trust your own data. |
| **Named graph** (this page) | A `Graph` declares **edge definitions** that constrain which vertex collections each edge type may connect. The server validates every edge insert. | Multi-edge-type graphs, type safety, multi-tenant integrity, *gharial* AQL traversals. |

The named graph approach is what ArangoDB documents call [*gharial*](https://docs.arangodb.com/stable/graphs/general-graphs/) — the HTTP API behind `Graph` is `/_api/gharial`.

## Define a graph

A graph is described by one or more `EdgeDefinition` value objects.

```php
use oihana\arango\clients\graph\EdgeDefinition ;

$employs = new EdgeDefinition(
    collection : 'employs' ,         // edge collection name
    from       : [ 'companies' ] ,    // allowed source vertex collections
    to         : [ 'people' ] ,       // allowed target vertex collections
) ;

$graph = $db->createGraph( 'workplaces' , [ $employs ] ) ;
```

`createGraph()` creates the graph, the edge collection, and the vertex collections if they don't exist, then returns a `Graph` handle.

You can also build the handle first and inspect it before creating:

```php
$graph = $db->graph( 'workplaces' ) ;   // lazy, no HTTP

if ( ! $graph->exists() )
{
    $graph->create( [ $employs ] ) ;
}
```

`createGraph()` accepts a third `$options` array forwarded to the server: `orphanCollections`, `numberOfShards`, `replicationFactor`, `writeConcern`, `waitForSync`, plus Enterprise-only knobs (`isSmart`, `smartGraphAttribute`, `isDisjoint`, `satellites`).

## Manage edge definitions

```php
// Add a new edge type to an existing graph.
$friendship = new EdgeDefinition( 'knows' , [ 'people' ] , [ 'people' ] ) ;
$graph->addEdgeDefinition( $friendship ) ;

// Replace an existing definition (e.g. widen allowed targets).
$graph->replaceEdgeDefinition(
    new EdgeDefinition( 'employs' , [ 'companies' ] , [ 'people' , 'contractors' ] ) ,
) ;

// Remove. Optionally drop the underlying collection.
$graph->removeEdgeDefinition( 'employs' , dropCollection: true ) ;
```

## Vertex and edge collections

Once a graph exists, you don't manipulate its collections directly with `Database::collection()` — you go through the graph so the server can enforce constraints.

```php
$companies = $graph->vertexCollection( 'companies' ) ;    // factory, no HTTP
$people    = $graph->vertexCollection( 'people' ) ;
$employs   = $graph->edgeCollection( 'employs' ) ;
```

`vertexCollections()` and `edgeCollections()` (no `Collection` suffix in the method name) return the names of all the collections this graph references. `orphanCollections()` lists vertex collections registered but not yet part of any edge definition — useful while you're growing the model.

## Vertex CRUD

`GraphVertexCollection` exposes the same CRUD shape as `Collection` (returns immutable `Document` objects), routed through `/_api/gharial/{graph}/vertex/{collection}`:

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

Options match `Collection`: `returnNew`, `returnOld`, `waitForSync`, `keepNull`, `rev`.

## Edge CRUD

`GraphEdgeCollection` works the same way, but returns `Edge` objects (a `Document` subclass with `getFrom()` and `getTo()` accessors) and **validates `_from`/`_to` against the edge definition**:

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

If you try to insert an edge whose `_from` points at a collection not listed in the edge definition, the server rejects it. That's the whole point of using `Graph` over raw `EdgeCollection`.

## Traversal — AQL with `GRAPH`

There is **no `traverse()` method** on `Graph` or `Database`. Traversals are written in AQL, which knows about graphs natively:

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

Direction keywords: `OUTBOUND` (`_from → _to`), `INBOUND` (`_to → _from`), `ANY` (either). Range `1..3` is depth — `1..3` means "between one and three hops away".

The `GRAPH 'name'` clause tells the engine to honour the graph's edge definitions. You can also traverse against raw edge collections with `IN ... edges`, but you lose the type-safety guarantee.

> **Anonymous traversals and the cluster.** A traversal over raw edge collections (no `GRAPH`) must, **in a cluster**, declare the reachable vertex collections through a `WITH` clause at the top of the query, otherwise the engine risks a deadlock. The `Edges` model traversal methods (`getOutboundVertices()`, `getInboundVertices()`, `getAnyVertices()`, `countVertices()`…) handle this automatically from the `_from` / `_to` models; see [`aqlWith()`](../aql/aql-operations.md#aqlwith).

For everything you can express in a traversal — pruning, filtering by edge attributes, weighted shortest paths — see the official [Graph traversals](https://docs.arangodb.com/stable/aql/graphs/) AQL reference.

## Drop a graph

```php
$graph->drop() ;                          // graph definition only
$graph->drop( dropCollections: true ) ;   // also drops vertex + edge collections — data loss
```

## Where next

- [AQL queries and Cursors](aql.md) — write the queries that drive your traversals.
- [Transactions](transactions.md) — keep multi-edge writes atomic.
- [HTTP client overview](README.md) — architecture and configuration.
