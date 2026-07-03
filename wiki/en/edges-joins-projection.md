# AQL edge and join projection

This page describes the projection of the **relations**: following an edge, resolving a stored reference (join), traversing a hierarchy, wrapping the result under a key. The transverse projection mechanisms — skins (`Field::SKINS`, `AQL::SKIN_FIELDS`), permissions (`AQL::REQUIRES`), transformations (`Field::ALTERS`) — are described in [Field projection](projection.md) and apply here identically.

## Table of contents

1. [Composed projection — `AQL::FIELDS` + `AQL::EDGES` on the edge definition](#composed-projection--aqlfields--aqledges-on-the-edge-definition)
2. [Hierarchical traversal — `AQL::MAX_DEPTH` / `AQL::MIN_DEPTH`](#hierarchical-traversal--aqlmax_depth--aqlmin_depth)
3. [Projecting edge properties — `Field::SCOPE`](#projecting-edge-properties--fieldscope)
4. [Wrapping the reference under a key — `Filter::WRAP`](#wrapping-the-reference-under-a-key--filterwrap)
5. [Projecting a *join* — `Filter::JOIN` / `Filter::JOINS`](#projecting-a-join--filterjoin--filterjoins)
6. [Breaking an INBOUND cycle with `AQL::SKIN`](#breaking-an-inbound-cycle-with-aqlskin)

## Composed projection — `AQL::FIELDS` + `AQL::EDGES` on the edge definition

When an edge points to a complex document, declare its projection by composing `AQL::FIELDS` and `AQL::EDGES` directly on the edge definition inside `AQL::EDGES`. The pattern is illustrated by `employeeEdge.php`:

```php
// Host-project example (`Acme\functions\edges\employeeEdge`).
function employeeEdge(
    ?string $employeePath     = Paths::PEOPLE ,
    ?string $workLocationPath = Paths::LOCATIONS ,
) :array
{
    return
    [
        AQL::MODEL  => EdgesDefinition::CUSTOMER_HAS_EMPLOYEE ,
        AQL::SORT   => Prop::POSITION ,
        AQL::FIELDS => person
        ([
            Prop::ID            => Filter::DEFAULT ,
            Prop::ACTIVE        => Filter::DEFAULT ,
            Prop::ADDRESS       => Filter::DEFAULT ,
            Prop::FAMILY_NAME   => Filter::DEFAULT ,
            Prop::GIVEN_NAME    => Filter::DEFAULT ,
            Prop::WORK_LOCATION => Filter::EDGE ,    // sub-edge declared below
        ] , $employeePath ) ,
        AQL::EDGES =>
        [
            Prop::WORK_LOCATION => workLocationEdge( $workLocationPath ) ,
        ] ,
    ] ;
}
```

And on the consuming DI side:

```php
// customers.php
AQL::EDGES =>
[
    Prop::EMPLOYEE => employeeEdge() ,
    Prop::LOCATION => locationEdge() ,
]
```

Important points:

- `AQL::FIELDS` on the edge definition **is read** by `buildEdgeVariable`. This is the effective projection used to hydrate the target document.
- `AQL::EDGES` on the edge definition declares the sub-edges referenced by `Filter::EDGE` or `Filter::EDGES` markers in the projection.
- `Field::FIELDS` placed **inline at the parent field level** is ignored for `Filter::EDGES` (it's only honoured for `Filter::DOCUMENT` and `Filter::MAP`). A common pitfall: declare the projection at the right level (on the edge definition, not on the parent field).

## Hierarchical traversal — `AQL::MAX_DEPTH` / `AQL::MIN_DEPTH`

By default a `Filter::EDGES` projection follows the relation **one level deep** — the direct children (or parents). For a **self-referential** relation — a concept linked to other concepts of the same collection, i.e. a hierarchy (a thesaurus, a category tree, an org chart) — you can follow the relation across **several levels in a single traversal** by declaring a depth on the edge definition:

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\Traversal ;

AQL::FIELDS =>
[
    Prop::DESCENDANTS => Filter::EDGES , // the projected field
],

AQL::EDGES =>
[
    Prop::DESCENDANTS =>
    [
        AQL::MODEL     => 'concept_links' ,     // the (self-referential) edge model
        AQL::DIRECTION => Traversal::OUTBOUND ,  // OUTBOUND = descend to children
        AQL::MAX_DEPTH => 5 ,                    // follow up to 5 levels
    ],
],
```

The generated sub-query becomes a **ranged** traversal:

```aql
LET descendants = ( FOR vertex, edge IN 1..5 OUTBOUND doc concept_links
    OPTIONS { "order": "bfs", "uniqueVertices": "global" }
    SORT edge.created DESC
    RETURN { … } )
```

### Direction — descend or ascend

The depth applies to whichever `AQL::DIRECTION` you declare:

- `Traversal::OUTBOUND` — descend the hierarchy (a node → its descendants).
- `Traversal::INBOUND` — ascend the hierarchy (a node → its ancestors, the chain to the root).

### Rules and defaults

- **No depth declared → unchanged.** Without `AQL::MIN_DEPTH` / `AQL::MAX_DEPTH`, the traversal stays at depth 1 and the generated AQL is **strictly identical** to before — fully backward-compatible.
- **`AQL::MAX_DEPTH` alone** defaults the lower bound to `1` (`1..N`), the natural full descent/ascent.
- **`AQL::MIN_DEPTH` alone is rejected.** ArangoDB requires a bounded range, and an unbounded traversal over a self-referential edge would risk a runaway cycle, so a ranged projection **must** declare `AQL::MAX_DEPTH` — otherwise `buildEdgeVariable` throws an `UnexpectedValueException`.
- The result is a **flat list** of all matched vertices across the depth range (not a nested tree). To turn it back into a nested `children[]` structure, reconstruct it from the flat list (see the roadmap entry on hierarchy reconstruction).

> **Homogeneous only.** A depth range assumes the **same** type at every level (a self-referential edge). For a heterogeneous chain where each level is a different type (`Type1 → Type2 → Type3`), do **not** use a depth — declare one nested edge level per type instead (each with its own `AQL::MODEL` / `AQL::FIELDS`), as shown in *Composed projection* above.

### Reconstruction metadata — `AQL::WITH_PATH`

The depth traversal returns a **flat list**. To turn it back into a nested tree you need, for every node, **who its parent is**. Two situations:

- **The document already stores its parent** (e.g. a `broader` / `parentId` field). Nothing to do — project that field and reconstruct from it.
- **The parent link lives only in the edges** (the document does not store it). Opt in to `AQL::WITH_PATH => true` on the edge definition: the traversal then exposes the `path` variable and injects two computed keys into each projected element:
  - `_parent` (`AQL::_PARENT`) — the `_key` of the immediate parent (the node one step closer to the start vertex), i.e. `path.vertices[-2]._key`.
  - `_depth` (`AQL::_DEPTH`) — the traversal depth, i.e. `LENGTH(path.edges)`.

```php
AQL::EDGES =>
[
    Prop::DESCENDANTS =>
    [
        AQL::MODEL     => 'concept_links' ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::MAX_DEPTH => 5 ,
        AQL::WITH_PATH => true , // inject _parent / _depth
    ],
],
```

```aql
LET descendants = ( FOR vertex, edge, path IN 1..5 OUTBOUND doc concept_links OPTIONS { … }
    RETURN { _key: vertex._key, name: vertex.name,
             _parent: path.vertices[-2]._key, _depth: LENGTH(path.edges) } )
```

Notes:

- **Off by default → unchanged.** Without `AQL::WITH_PATH` no `path` variable is emitted and the AQL is identical.
- **Whole-vertex projection.** When the edge declares no `AQL::FIELDS` (the element is the bare vertex), the metadata is grafted with `MERGE(vertex, { _parent, _depth })`.
- **Scalar projection.** A `Arango::PROPERTY` projection returns a scalar, so it has no object to carry the metadata: `AQL::WITH_PATH` is **ignored** there (and no `path` variable is emitted).
- A node at depth 1 has `_parent` equal to the **start vertex** key — the root from which the flat list is reconstructed into a `children[]` tree.

### Rebuilding the tree — `buildTree()` / `buildTreeAlter()`

The flat list is turned into a nested `children[]` tree by `buildTree()` — a pure O(n) helper (no extra query). It groups nodes by parent and descends from the root:

```php
use function oihana\arango\models\helpers\buildTree ;

$tree = buildTree( $flat , rootKey: 'animals' ) ; // parent source defaults to '_parent'
```

`buildTree()` is **cycle-safe** (a node already on the current branch is not descended into again) and accepts the parent source, the children key and the identity field as parameters — so it works both from the `AQL::WITH_PATH` `_parent` and from a stored parent field:

```php
$tree = buildTree( $flat , parentSource: 'broader' , rootKey: 'animals' ) ; // stored-parent case
```

To have the tree delivered **automatically** in the response, wire `buildTreeAlter()` as an `Alter::MAP` on the hierarchy field. The alteration runs after the query, reads the root from the enclosing document's `_key`, and replaces the flat list with the nested tree:

```php
use oihana\arango\models\enums\Alter ;
use function oihana\arango\models\helpers\buildTreeAlter ;

AQL::FIELDS =>
[
    Prop::DESCENDANTS =>
    [
        Field::FILTER => Filter::EDGES ,
        Field::ALTERS => [ [ Alter::MAP , buildTreeAlter() ] ] , // flat → children[]
    ],
],
AQL::EDGES =>
[
    Prop::DESCENDANTS =>
    [
        AQL::MODEL     => 'concept_links' ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::MAX_DEPTH => 5 ,       // Lot A — descend up to 5 levels
        AQL::WITH_PATH => true ,     // Lot B — inject _parent used by buildTree
    ],
],
```

The consumer then receives, on each document, a `descendants` field already nested as `children[]` — a single traversal plus an in-memory reshape, at any depth.

> **Single parent per node.** `buildTree()` expects each node to reference **one** parent. With `AQL::WITH_PATH` this is guaranteed by the traversal's global vertex uniqueness. A polyhierarchy where a concept has several parents (an array-valued `broader`) is out of scope for the tree reshape — the flat list (with `?filter=` / `quant`) remains the correct surface for that.

## Projecting edge properties — `Field::SCOPE`

By default, the fields declared in an edge definition's `AQL::FIELDS` are projected from the **target vertex** of the traversal (the other end of the relationship). But an edge is more than a connector: it often carries its own metadata (`created`, `weight`, `role`, `order`, …). The `Field::SCOPE` marker hoists those properties into the **same object**, next to the vertex fields.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Field ;
use oihana\arango\enums\Filter ;
use oihana\arango\enums\Scope ;

AQL::EDGES =>
[
    Prop::FRIENDS =>
    [
        AQL::MODEL  => EdgesDefinition::PERSON_HAS_FRIEND ,
        AQL::FIELDS =>
        [
            Prop::NAME => Filter::DEFAULT ,                                  // from the target vertex
            'since'    => [ Field::FILTER => Filter::DATETIME ,
                            Field::NAME  => 'created' ,
                            Field::SCOPE => Scope::EDGE ] ,                  // from the edge
            'weight'   => [ Field::FILTER => Filter::NUMBER ,
                            Field::SCOPE => Scope::EDGE ] ,                  // from the edge
        ] ,
    ] ,
]
```

Generated AQL (the inner `RETURN` reads `v` **and** `e`):

```aql
LET friends = (
  FOR v, e IN OUTBOUND doc person_has_friend
  SORT e.created DESC
  RETURN { name: v.name, since: ... e.created ..., weight: TO_NUMBER(e.weight) }
)
```

Rules and key points:

- **Scope value.** `Scope::VERTEX` (default) reads from the vertex, `Scope::EDGE` reads from the edge. The constants are exactly equal to `AQL::VERTEX` / `AQL::EDGE`, so `Field::SCOPE => AQL::EDGE` is strictly equivalent and avoids an extra `use` when `AQL` is already imported.
- **Absent = vertex.** A field with no `Field::SCOPE` behaves as before — the feature is fully backward compatible.
- **Name collisions.** Both sources may carry the same attribute (`name` on the vertex AND on the edge). Since the **field key = the output label**, just give the edge field a distinct label and alias its source with `Field::NAME`: `'edgeName' => [ Field::NAME => 'name' , Field::SCOPE => Scope::EDGE ]`.
- **Order.** The projection preserves the declaration order of the fields in `AQL::FIELDS` — vertex and edge fields can be freely interleaved.
- **Guardrail — outside a traversal.** `Field::SCOPE => edge` only makes sense inside an edge sub-query. Placed at the root, on a *join*, or in a nested sub-document (where the edge no longer exists), it **throws** (`UnsupportedOperationException`) rather than silently falling back to the vertex.
- **Guardrail — structural filters.** `Field::SCOPE => edge` on a structural filter (`Filter::EDGE`, `Filter::EDGES`, `Filter::JOIN`, `Filter::JOINS`, `Filter::EDGES_COUNT`, …) would have no effect (those filters are driven by a precomputed variable, not by the document reference): it **throws** instead of being silently ignored.

## Wrapping the reference under a key — `Filter::WRAP`

`Field::SCOPE` hoists a **scalar** edge metadata next to the vertex fields (flat projection). Its symmetric counterpart, `Filter::WRAP`, does the opposite for an **object**: it **wraps the whole current reference under a named key**, instead of flattening its fields at the root.

The typical case: an edge traversal returns the target vertex *flat* by default. When the output model expects the related entity **nested under a sub-key** (e.g. `subject`), next to the edge metadata (`role`), `Filter::WRAP` produces that nested shape — impossible with the flat projection.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Field ;
use oihana\arango\enums\Filter ;
use oihana\arango\enums\Scope ;

AQL::EDGES =>
[
    'memberships' =>
    [
        AQL::MODEL  => EdgesDefinition::PERSON_HAS_TEAM ,
        AQL::FIELDS =>
        [
            'role'    => [ Field::SCOPE => Scope::EDGE ] ,                   // scalar, from the edge
            'subject' =>                                                     // object, wraps the vertex
            [
                Field::FILTER => Filter::WRAP ,
                Field::FIELDS =>
                [
                    'id'   => Filter::DEFAULT ,
                    'name' => Filter::DEFAULT ,
                ] ,
            ] ,
        ] ,
    ] ,
]
```

Generated AQL (the vertex is nested under `subject`, the edge stays flat):

```aql
LET memberships = (
  FOR v, e IN OUTBOUND doc person_has_team
  RETURN { role: e.role, subject: { id: v.id, name: v.name } }
)
```

Rules and key points:

- **Field whitelist required by default.** `Field::FIELDS` projects the sub-fields **against the reference itself** (`v.id`), not against a sub-attribute (`v.subject.id`) — the key difference with `Filter::DOCUMENT`, which dives into `ref.key`. Without `Field::FIELDS`, the projection **throws** (`UnsupportedOperationException`): embedding the whole object must be deliberate.
- **Whole object — `Field::RAW` opt-in.** To embed the reference as-is, with no field list, declare `Field::RAW => true`: the output becomes `subject: v` (every vertex attribute, no projection). It is the only way to omit `Field::FIELDS`.
- **Vertex by default, edge possible.** Like any field, `Field::SCOPE => Scope::EDGE` switches the wrapped reference to the edge — wrapping **the whole edge** under the key (handy to expose the link itself as an object).
- **Difference with `Filter::DOCUMENT`.** `Filter::DOCUMENT` nests an **existing sub-attribute** (`address: { city: v.address.city }`). `Filter::WRAP` wraps **the reference itself** under a fresh key (`subject: { … v … }`).
- **Companion of `Field::SCOPE`.** `Field::SCOPE` hoists edge **scalars** flat; `Filter::WRAP` nests an **object** (vertex or edge) under a key. The two combine freely in the same `AQL::FIELDS`.

### Carrying the wrapped vertex's relations — `Field::EDGES`

A wrapped vertex can also carry **its own relations**, nested **under the same key**. The typical case: a list of links projected as `[{ subject: <vertex> }]`, where the `subject` is itself linked to a **third entity** by another edge (often reached `INBOUND`). You want that entity **nested inside the `subject`** (`subject.worksFor`), **in a single query** — neither flattened at the entry level nor fetched in a second round-trip.

The declaration reuses **exactly the top-level grammar**: the **cardinality marker** (`Filter::EDGE` single / `Filter::EDGES` list / `Filter::EDGES_COUNT` count) in `Field::FIELDS`, and the **sub-traversal definition** in `Field::EDGES`, under the same key. The sub-traversal starts **from the wrapped vertex** (not the root document).

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\Traversal ;
use oihana\arango\enums\Field ;
use oihana\arango\enums\Filter ;

// account --[account_has_identity]--> person   (the projected link)
// person  <--[org_has_member]-- organization   (the person's organization, INBOUND)
AQL::EDGES =>
[
    'identities' =>
    [
        AQL::MODEL  => EdgesDefinition::ACCOUNT_HAS_IDENTITY ,
        AQL::FIELDS =>
        [
            'subject' =>
            [
                Field::FILTER => Filter::WRAP ,
                Field::FIELDS =>
                [
                    'id'       => Filter::DEFAULT ,
                    'name'     => Filter::DEFAULT ,
                    'worksFor' => [ Field::FILTER => Filter::EDGE ] ,           // ← marker, just like at the root
                ] ,
                Field::EDGES =>                                                // ← sub-traversal definition
                [
                    'worksFor' =>
                    [
                        AQL::MODEL     => EdgesDefinition::ORG_HAS_MEMBER ,
                        AQL::DIRECTION => Traversal::INBOUND ,
                        AQL::FIELDS    => [ 'id' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
                    ] ,
                ] ,
            ] ,
        ] ,
    ] ,
]
```

Generated AQL (the sub-traversal starts from `v`, its `LET` is emitted inside the `FOR v`, the result is nested into `subject`):

```aql
LET identities = (
  FOR v, e IN OUTBOUND doc account_has_identity
    LET worksFor = ( FOR v2, e2 IN INBOUND v org_has_member RETURN { id: v2.id, name: v2.name } )
    RETURN {
      subject: {
        id: v.id, name: v.name,
        worksFor: ( IS_OBJECT(worksFor) ? worksFor : IS_ARRAY(worksFor) ? FIRST(worksFor) : null )
      }
    }
)
```

Rules and key points:

- **Everything works like at the root.** `Filter::EDGE` (single object), `Filter::EDGES` (list) and `Filter::EDGES_COUNT` (count) are used identically; permission gating (`Field::REQUIRES`), sorts and sub-projections all apply verbatim.
- **Both directions.** `AQL::DIRECTION => Traversal::INBOUND` (or `OUTBOUND`, the default) — the related entity is often reached `INBOUND`.
- **Natural depth.** The sub-traversal is an ordinary edge: it carries its own `AQL::EDGES` / `AQL::JOINS`, so the related entity can project further (`subject.worksFor.locatedIn`). Each level adds a `FOR` sub-query: this is a matter of **performance**, not a hard limit — keep the nesting shallow (2–3 levels).
- **`Field::RAW` excludes `Field::EDGES`.** A raw reference (`subject: v`) has no projected object to graft a relation onto — combining them **throws**.
- **Marker and definition go together.** As at the root, both are required: a definition in `Field::EDGES` without a matching marker in `Field::FIELDS` is simply **unused** (nothing is projected); conversely, a marker **without** a definition projects a **dangling `LET` reference** → an AQL runtime error. Always declare both.
- **Joins too.** The same mechanism applies to **joins**: a `Filter::JOIN` / `Filter::JOINS` marker in `Field::FIELDS` and a definition in a companion `Field::JOINS` — the join then resolves a stored reference **on the wrapped vertex** (`vertex.role`). `Field::EDGES` and `Field::JOINS` combine freely under the same key.
- **Retro-compatible.** A `Filter::WRAP` without `Field::EDGES` nor `Field::JOINS` behaves exactly as before.

## Projecting a *join* — `Filter::JOIN` / `Filter::JOINS`

Where an *edge* traverses an edge collection, a **join** resolves a **reference stored in the document itself** to the documents of another collection. The **field type** picks the cardinality, exactly like `Filter::EDGE` (single) vs `Filter::EDGES` (multiple):

- **`Filter::JOIN`** — the field holds **one** identifier → projects **the** joined document.
- **`Filter::JOINS`** — the field holds an **array of identifiers** → projects **the list** of joined documents.

The projection is declared in two parts: the field **type** in `AQL::FIELDS`, and the join **definition** (target collection, projection, sort) in `AQL::JOINS`, under the same key.

```php
AQL::FIELDS =>
[
    Prop::_KEY => Filter::DEFAULT ,
    'tracks'   => Filter::JOINS ,        // array of ids → joined documents
],
AQL::JOINS =>
[
    'tracks' =>
    [
        AQL::MODEL   => Models::TRACK ,                                            // target Documents model (DI)
        AQL::FIELDS  => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] , // projection of the joined docs
        Arango::SORT => 'name' ,                                                   // sort INSIDE the join
    ],
],
```

`GET /playlists/{id}` then returns `tracks` no longer as an array of ids, but as the **list of matching documents**. The generated AQL (simplified):

```aql
LET tracks = (
    FOR doc_join IN @@track
        FILTER doc_join._key IN ( IS_ARRAY( doc.tracks ) ? doc.tracks : [] )
        SORT doc_join.name ASC
        RETURN { _key: doc_join._key, name: doc_join.name }
)
```

> **Sorting a joined array happens INSIDE the join** (`Arango::SORT` on the join definition), not through the outer `?sort=` — which sorts the **parent documents**, never the content of a joined field. That is the correct separation.

Useful options on the join definition: `Arango::KEY` (join attribute, default `_key`), `Arango::PROPERTY` (point at a nested parent property as the key), `Arango::CONDITIONS` (extra filters), nested `AQL::FIELDS` / `AQL::EDGES` / `AQL::JOINS`, `AQL::SKIN` / `AQL::SKIN_FIELDS` (the joined projection varies with `?skin=`), `AQL::REQUIRES` ([permission gating](projection.md#permission-gated-edges-and-joins--aqlrequires)).

> Natural combination with [embedded array fields](db/arrays.md): a `tracks` field (an array of ids mutated element-by-element via `ArrayPropertyController`) can **at the same time** be projected as sorted joined documents in the `GET` via `Filter::JOINS` — no duplication.

## Breaking an INBOUND cycle with `AQL::SKIN`

INBOUND edges towards a document that points back to the source create a potentially infinite hydration cycle. Example: on a `Policy`, you want to expose INBOUND the list of `Service` that reference it. But a `Service` has `Policy` OUTBOUND, and each `Policy` projects its `Service` again, and so on.

The fix is `AQL::SKIN => Skin::MAIN` on the edge definition. The `Skin::MAIN` mode filters the target projection to keep only fields without a `Field::SKINS` marker — so sub-edges (all gated behind `Skin::FULL` or `Skin::DEFAULT`) are dropped and the cycle stops.

```php
// policies.php — reverse exposure of services
AQL::EDGES =>
[
    Prop::SERVICES_COUNT => Prop::SERVICES ,
    Prop::SERVICES       =>
    [
        AQL::MODEL     => EdgesDefinition::SERVICE_HAS_POLICIES ,
        AQL::DIRECTION => Traversal::INBOUND ,
        AQL::SKIN      => Skin::MAIN ,             // breaks the cycle
    ] ,
]
```

Without `AQL::SKIN => Skin::MAIN`, Xdebug aborts the request with a 500 error "infinite loop, aborted your script with a stack depth of '512' frames" on **every route** (the DI container compiles the `Documents` models when each Slim request boots). The symptom is misleading: it isn't the route that loops, it's the definition.

