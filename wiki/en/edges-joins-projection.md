# AQL edge and join projection

This page describes the projection of the **relations**: following an edge, resolving a stored reference (join), traversing a hierarchy, wrapping the result under a key. The transverse projection mechanisms — skins (`Field::SKINS`, `AQL::SKIN_FIELDS`), permissions (`AQL::REQUIRES`), transformations (`Field::ALTERS`) — are described in [Field projection](projection.md) and apply here identically.

> **Programmatic traversals.** The permission gate also holds for the explicit `getVertices()` / `getOutboundVertices()` / `getInboundVertices()` / `getAnyVertices()` traversals: the **target model**'s projection inherits its `Field::REQUIRES` / `AQL::REQUIRES` — the request authorizer is propagated into the target's projection, so a field or relation hidden from reading stays hidden through the edge (no field oracle via an edge). Fail-open when no authorizer is injected.

## Table of contents

1. [Composed projection — `AQL::FIELDS` + `AQL::EDGES` on the edge definition](#composed-projection--aqlfields--aqledges-on-the-edge-definition)
2. [Hierarchical traversal — `AQL::MAX_DEPTH` / `AQL::MIN_DEPTH`](#hierarchical-traversal--aqlmax_depth--aqlmin_depth)
3. [Projecting edge properties — `Field::SCOPE`](#projecting-edge-properties--fieldscope)
4. [Wrapping the reference under a key — `Filter::WRAP`](#wrapping-the-reference-under-a-key--filterwrap)
5. [Projecting a *join* — `Filter::JOIN` / `Filter::JOINS`](#projecting-a-join--filterjoin--filterjoins)
6. [Polymorphic join — target collection from a discriminator field](#polymorphic-join--target-collection-from-a-discriminator-field)
7. [Polymorphic edge — target edge from a discriminator field](#polymorphic-edge--target-edge-from-a-discriminator-field)
8. [Restricting the projected vertices — `AQL::WHERE` / `AQL::PRUNE`](#restricting-the-projected-vertices--aqlwhere)
9. [The count always says what the list shows — `Filter::EDGES_COUNT`](#the-count-always-says-what-the-list-shows--filteredges_count)
10. [Anchoring a relation elsewhere — `Arango::SOURCE`](#anchoring-a-relation-elsewhere--arangosource)
11. [Breaking an INBOUND cycle with `AQL::SKIN`](#breaking-an-inbound-cycle-with-aqlskin)

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
- This ad-hoc projection **inherits the target model's `Field::REQUIRES`**: a field declared here but hidden from reading on the target model stays hidden (dropped from the sub-query when the authorizer denies it), with no need to re-declare its permission on the definition. The gate applies to the **source** attribute (`Field::NAME` when aliased), never the output key. Fail-open: a field with no `Field::REQUIRES` on the target, or no authorizer, stays projected.
- `AQL::EDGES` on the edge definition declares the sub-edges referenced by `Filter::EDGE` or `Filter::EDGES` markers in the projection.
- `Field::FIELDS` placed **inline at the parent field level** is ignored for `Filter::EDGES` (it's only honoured for `Filter::DOCUMENT` and `Filter::MAP`). A common pitfall: declare the projection at the right level (on the edge definition, not on the parent field).

### Overriding the target gate for this relation — `Field::SELF_REQUIRES`

The inheritance above is deliberately **absolute**: a relation cannot decide, on its own, that some other permission is enough to see a field the target model hides. That is exactly what stops a masked field from leaking through a relation. But sometimes the relation *is* a legitimately broader context — you can read a colleague's `salary` only through `people:admin`, yet you should always see **your own** salary when the relation you follow is « my own employee record ».

`Field::SELF_REQUIRES` opens that door, and only that door. It is an **OR alternative** to the target model's `Field::REQUIRES`, honoured **only** when a relation re-projects the field through its own `AQL::FIELDS`. A first-level read of the model never goes through this path, so the model's own gate is untouched everywhere else.

The situation. On the `people` model, `salary` is masked behind `people:admin`. A relation re-projects it and adds a self-scoped alternative:

```php
// people model — the read gate everyone is measured against
'salary' => [ Field::REQUIRES => 'people:admin' ] ,

// on a relation's AQL::FIELDS — same field, plus a relation-scoped OR alternative
'salary' =>
[
    Field::SELF_REQUIRES => 'people:self' , // a string, or a list of subjects (OR)
] ,
```

| Caller | `people:admin` | `people:self` | `salary` through the relation |
| --- | --- | --- | --- |
| an HR admin | granted | — | **kept** (target gate already passes) |
| the record's owner | refused | granted | **kept** (the self alternative passes) |
| anyone else | refused | refused | dropped |

The rules, one at a time:

- **OR, never AND.** The field survives if the target gate passes **or** at least one `Field::SELF_REQUIRES` subject is granted. A list is a logical OR, exactly like `Field::REQUIRES`.
- **This helper only.** `Field::SELF_REQUIRES` is read by `authorizeTargetFields()` — the relation re-gate — and by nothing else. A model's direct read (`list`, `get`, a root projection) never consults it, so it can never widen a first-level read.
- **An empty or malformed value is ignored.** `Field::SELF_REQUIRES => []` (or any value with no string subject) is **not** an override — the field stays dropped. It cannot borrow the « no subject → allowed » fail-open of a normal gate to silently re-expose a masked field.
- **Fail-open unchanged.** With no authorizer wired, the field is kept regardless — same as everywhere else.

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

The same key is read identically by **every** edge surface — the projection, the
count, the `?group=` dimension, the hierarchical filters and the nested relation
walker:

- **The default is `Traversal::OUTBOUND`**: an edge leaves the document that declares it.
- ⚠ **An unknown keyword is refused**, never quietly replaced by the default. It used
  to be folded back, so a mistyped `AQL::DIRECTION => 'OUTBOUD'` compiled to `OUTBOUND`
  without a word — and on a relation actually reached `INBOUND`, that silence is an
  **empty projection in `200`**, indistinguishable from "this relation has no
  vertices". A malformed *declaration* is server-side, so it throws; an unknown key
  coming from the *request* keeps being dropped in silence, the frontier drawn
  everywhere else.
- **`Traversal::ANY` needs both ends on the same collection.** `ANY` walks the edge
  in both directions, so on a **self-referential** relation (a thesaurus,
  `user_follows`) it is exactly right and projects as before. On a relation whose
  two ends are **different** collections it is **refused**: a projection reaches one
  vertex model, and the far side's vertices used to come back projected with the
  near side's fields — and gated by the near side's `Field::REQUIRES`. Declare
  `INBOUND` or `OUTBOUND` instead, or two relations.
  > This restriction is specific to a **projected** relation, which has a model to
  > resolve. A linked **facet** declares an edge *collection*, not a model, so `ANY`
  > stays unrestricted there — see [Traversal direction](db/facets.md#traversal-direction-aqldirection).

### Rules and defaults

- **No depth declared → unchanged.** Without `AQL::MIN_DEPTH` / `AQL::MAX_DEPTH`, the traversal stays at depth 1 and the generated AQL is **strictly identical** to before — fully backward-compatible.
- **`AQL::MAX_DEPTH` alone** defaults the lower bound to `1` (`1..N`), the natural full descent/ascent.
- **`AQL::MIN_DEPTH` alone is rejected.** ArangoDB requires a bounded range, and an unbounded traversal over a self-referential edge would risk a runaway cycle, so a ranged projection **must** declare `AQL::MAX_DEPTH` — otherwise `buildEdgeVariable` throws an `UnexpectedValueException`.
- The result is a **flat list** of all matched vertices across the depth range (not a nested tree). To turn it back into a nested `children[]` structure, reconstruct it from the flat list (see the roadmap entry on hierarchy reconstruction).

> ⚠️ **Restricting a hierarchy takes TWO keys, not one.** On a ranged traversal a plain filter hides the targeted vertex but **not its descent**: the walk keeps going through it. You need `AQL::WHERE` *and* `AQL::PRUNE` — see [Restricting the projected vertices](#restricting-the-projected-vertices--aqlwhere).

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

> **Filtered input → holes; pruned input → a clean tree.** `buildTree()` assumes the flat list is *connected* down from the root. A [`?filter=`](controllers/README.md) on the traversal can break that: a vertex whose ancestor was filtered out becomes an **orphan** — its `_parent` points at a removed node. The reshape then depends on the root mode:
> - with `buildTreeAlter()` (an explicit `rootKey`), the orphan — and its own sub-tree — is **dropped**: the branch is truncated at the filtered vertex ;
> - with inferred roots (`rootKey: null`), the orphan instead **floats up to become a root**, detached from its real ancestor.
>
> `?prune=` has no such effect: it cuts the whole branch under a non-matching vertex, so no survivor is ever left orphaned and the reconstructed tree stays connected. **Rule of thumb: to filter *and* rebuild a tree, prefer `?prune=` over `?filter=`** — or knowingly accept the truncation / floating above (a flat list, by contrast, is always a correct surface for `?filter=`).

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

### Restricting the joined documents — `Arango::CONDITIONS`

**The problem, plainly.** A join brings back documents. If your application masks some of them — disabled, out of scope — the main list hides them, but the **parent's record** keeps naming them in its joined field. You cannot enumerate them, yet you read them by ricochet.

This is, for joins, the equivalent of what [`AQL::WHERE`](#restricting-the-projected-vertices--aqlwhere) does for edges.

**The declaration.** `Arango::CONDITIONS` carries extra predicates, appended to the key match. Two shapes: a plain array of AQL predicates, or — the useful one — a **callable** returning them.

```php
'hasPart' =>
[
    AQL::MODEL          => 'Products' ,
    Arango::CONDITIONS  => fn( string $part ) :array => [ $part . '.active == true' ] ,
] ,
```

```aql
LET hasPart = (FOR doc_join_18 IN products
                 FILTER doc_join_18._key IN doc.hasPart && doc_join_18.active == true
                 RETURN doc_join_18)
```

> **Why a callable rather than a string?** The loop variable name is **generated** (`doc_join_18…`), so you cannot hardcode it. The callable receives it.

**The three arguments.** They are **always** passed:

| # | Argument | What it is for |
|---|---|---|
| 1 | `$join` | the loop variable, to prefix your attributes |
| 2 | `$parent` | the enclosing document, to compare against the parent |
| 3 | `$init` | the request-level init |

⚠️ **Declare only what you need.** PHP discards the surplus handed to a userland callable, so `fn( $join )` and `fn( $join , $parent )` keep working untouched — nothing to migrate. The opposite is what fails: declaring a parameter nobody passes you.

Of the init, only **three keys are contractual**: `Arango::AUTHORIZER`, `AQL::SKIN` and `Arango::BINDS`. The rest is internal — do not rely on it.

**Returning `[]` emits no predicate**, and that is the important part: it is how a scope stays inert outside an HTTP request.

```php
Arango::CONDITIONS => function( string $part , string $parent , array $init ) :array
{
    if ( !isset( $init[ Arango::AUTHORIZER ] ) ) { return [] ; }  // CLI, harvesting, tests
    return [ $part . '.productType NOT IN @inactiveTypes' ] ;
} ,
```

With no authorizer the query is byte-for-byte the previous one, and no bind has to be supplied. Your CLI commands do not move a line.

**An object method or an invokable** works too, when the scope needs a service:

```php
Arango::CONDITIONS => [ $scopeProvider , 'productConditions' ] ,
```

> ⚠️ **The runtime-bind trap.** If your predicate references `@myValue` **as text**, the automatic pruning of unreferenced binds cannot see it — it looks for `aqlBindRef()` objects, and a `@` inside a string is indistinguishable from a literal `@`. So if a skin can drop this join, the whole query is rejected (*bind parameter … was not declared*). Two answers: prefer a **literal** predicate where possible, or name the bind explicitly in the 4th argument of `prepareAndExecute( $query , $binds , $options , [ 'myValue' ] )`.

> A non-array return raises — never a silently absent filter.

## Polymorphic join — target collection from a discriminator field

A regular join targets **one** fixed collection (`AQL::MODEL`). A **polymorphic join** picks its target collection **at query time**, from a value of the parent document itself. The typical case: a `PricingConditionSelector` carrying an `areaScope` (the zone *type*) and an `areaServed` (the *key*), which must resolve into `warehouses` when the scope is `#Warehouse`, into `subsidiaries` when it is `#Company`.

```json
"selector": {
    "areaScope":  "https://schema.oihana.xyz/PricingAreaScope#Warehouse",
    "areaServed": "w1"
}
```

The definition replaces `AQL::MODEL` with three keys:

- **`Arango::DISCRIMINATOR`** — the parent field that decides (a scalar path, e.g. `selector.areaScope`).
- **`Arango::MAP`** — the `type => join-definition` table, one branch per value; each branch is **a regular join definition** (with its own `AQL::MODEL`, projection, sort…).
- **`Arango::FALLBACK`** — (optional) the branch used when the value matches **none** of the declared types; `null` = none.

The field stays declared as `Filter::JOIN` (single) or `Filter::JOINS` (list) in `AQL::FIELDS` — **no new marker**: it is the presence of `Arango::MAP` + `Arango::DISCRIMINATOR` in the definition that switches to polymorphic mode.

```php
AQL::FIELDS =>
[
    'area' => Filter::JOIN ,                     // single document (JOINS for a list)
],
AQL::JOINS =>
[
    'area' =>
    [
        Arango::DISCRIMINATOR => 'selector.areaScope' ,   // the parent field that decides
        Arango::PROPERTY      => 'selector.areaServed' ,  // the parent key (shared by branches)
        Arango::MAP           =>
        [
            'https://schema.oihana.xyz/PricingAreaScope#Warehouse' =>
            [
                AQL::MODEL  => Models::WAREHOUSE ,
                AQL::FIELDS => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
            ] ,
            'https://schema.oihana.xyz/PricingAreaScope#Company' =>
            [
                AQL::MODEL  => Models::SUBSIDIARY ,
                AQL::FIELDS => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
            ] ,
        ] ,
        Arango::FALLBACK => null ,                          // unknown type → null (JOIN) / [] (JOINS)
    ],
],
```

AQL forbids a computed collection in a `FOR … IN …`, so the join is compiled as an **`APPEND` of guarded static branches**: one join sub-query per branch, each guarded by an equality on the discriminator, so only one branch yields rows. The generated AQL (simplified):

```aql
LET area = APPEND(
    ( FOR doc_join IN @@warehouse
        FILTER doc_join._key == doc.selector.areaServed
           && doc.selector.areaScope == "https://schema.oihana.xyz/PricingAreaScope#Warehouse"
        RETURN { _key: doc_join._key, name: doc_join.name } ) ,
    ( FOR doc_join IN @@subsidiary
        FILTER doc_join._key == doc.selector.areaServed
           && doc.selector.areaScope == "https://schema.oihana.xyz/PricingAreaScope#Company"
        RETURN { _key: doc_join._key, name: doc_join.name } )
)
```

> **The `LET` holds an array, like any join.** Only one branch is non-empty (the guards are exclusive); the projection then unwraps that array **exactly like a regular join** — `FIRST()` for `Filter::JOIN`, the whole array for `Filter::JOINS`. Nothing to change on the projection side.

> **Each branch is gated on its own.** A `Field::REQUIRES` / `AQL::REQUIRES` on a branch makes it **disappear from the `APPEND`** when the permission is denied — its collection is never queried, so neither a value nor an existence bit of the hidden type can leak (fail-closed). This gate **composes** (logical AND) with the field- / definition-level gates that guard the whole join.

> **The fallback never catches a denied type.** The `Arango::FALLBACK` branch is guarded by `NOT IN [ …every declared type… ]` — including types whose branch was denied. A document of a denied type therefore routes to **nothing**, never to the fallback: no oracle. When every branch is dropped the `LET` holds `[]` (projection → `null` / `[]`), never a broken clause.

Useful options: the parent key (`Arango::PROPERTY`, default the field name) and the join attribute (`Arango::KEY`, default `_key`) declared at the top level are **shared** as defaults across branches; a branch may override its own key. Each branch also accepts the whole vocabulary of a regular join (`Arango::CONDITIONS`, `Arango::SORT`, nested `AQL::EDGES` / `AQL::JOINS`, `AQL::SKIN`).

## Polymorphic edge — target edge from a discriminator field

The **edge** counterpart of the [polymorphic join](#polymorphic-join--target-collection-from-a-discriminator-field). Where a regular edge traverses **one** fixed edge collection (`AQL::MODEL`), a **polymorphic edge** picks the edge (and hence the target vertex) to traverse **at query time**, from a value of the **start vertex** (the source document). Example: a node carries a `kind` field, and you want to follow `warehouse_edges` when `kind == "warehouse"`, `company_edges` when `kind == "company"`.

The definition replaces `AQL::MODEL` with the same three keys as the polymorphic join:

- **`Arango::DISCRIMINATOR`** — the **start-vertex** field that decides (a scalar path, e.g. `kind`).
- **`Arango::MAP`** — the `type => edge-definition` table, one branch per value; each branch is **a regular edge definition** (its own `AQL::MODEL`, `AQL::DIRECTION`, projection, depth…).
- **`Arango::FALLBACK`** — (optional) the branch for a value matching **none** of the declared types; `null` = none.

The field stays declared as `Filter::EDGE` (single) or `Filter::EDGES` (list) in `AQL::FIELDS` — **no new marker**: the presence of `Arango::MAP` + `Arango::DISCRIMINATOR` switches to polymorphic mode (shared `isPolymorphic` detection, common to joins and edges).

```php
AQL::FIELDS =>
[
    'area' => Filter::EDGE ,                      // single vertex (EDGES for a list)
],
AQL::EDGES =>
[
    'area' =>
    [
        Arango::DISCRIMINATOR => 'kind' ,          // the start-vertex field that decides
        Arango::MAP           =>
        [
            'warehouse' =>
            [
                AQL::MODEL  => Edges::WAREHOUSE ,   // source edge → warehouses
                AQL::FIELDS => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
            ] ,
            'company' =>
            [
                AQL::MODEL  => Edges::COMPANY ,     // source edge → subsidiaries
                AQL::FIELDS => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
            ] ,
        ] ,
        Arango::FALLBACK => null ,                  // unknown type → null (EDGE) / [] (EDGES)
    ],
],
```

AQL forbids a computed collection in a `FOR … IN <dir> … <collection>`, so the edge is compiled as an **`APPEND` of guarded static traversals**: one traversal per branch, each guarded by an equality on the discriminator, so only one branch yields rows. The generated AQL (simplified):

```aql
LET area = APPEND(
    ( FOR vertex, edge IN OUTBOUND doc warehouse_edges
        FILTER doc.kind == "warehouse"
        RETURN { _key: vertex._key, name: vertex.name } ) ,
    ( FOR vertex, edge IN OUTBOUND doc company_edges
        FILTER doc.kind == "company"
        RETURN { _key: vertex._key, name: vertex.name } )
)
```

> **The `LET` holds an array, like any edge.** Only one branch is non-empty; the projection then unwraps it **exactly like a regular edge** — `FIRST()` for `Filter::EDGE`, the whole array for `Filter::EDGES`. Nothing to change on the projection side.

> **Each branch is a full edge definition**: it may declare its own `AQL::DIRECTION` (OUTBOUND/INBOUND), depth (`AQL::MAX_DEPTH`), etc. Homogeneous projections across branches are recommended.

> **Security — identical to the polymorphic join.** A branch denied by permission (`Field::REQUIRES` / `AQL::REQUIRES`) is **dropped from the `APPEND`** (fail-closed: its collection is never traversed). The `Arango::FALLBACK` branch is guarded by `NOT IN [ …every declared type… ]`, including denied types — a document of a denied type routes to **nothing**, never to the fallback (no oracle). Every branch dropped ⇒ the `LET` holds `[]`. The anti-oracle logic is **shared** with the join (a single `buildPolymorphicRelationVariable` assembler).

> ⚠️ **Polymorphic `Filter::EDGES_COUNT`: unsupported (v1).** Counting uses `LENGTH(traversal)`, incompatible with the `APPEND`-of-branches pattern. A count entry stays counted on the model's fixed edge collection (classic behaviour).

## Restricting the projected vertices — `AQL::WHERE`

**The problem, plainly.** A consumer masks some documents of a collection — disabled, out of scope, whatever the reason. The main list hides them correctly. But the parent's record keeps naming them inside its children array: they cannot be enumerated, yet they are read **by ricochet**, through the relation of a served document.

Think of a directory with pages torn out, whose back-of-book index still lists the removed names.

**What `AQL::WHERE` adds.** A predicate declared on the **relation definition**, compiled against the **traversed vertex**, and therefore applied everywhere that definition is used — the list, the record, the sub-route — with no per-entry-point wiring.

**The situation.** A thesaurus exposes each concept's descent; the consumer hides some terms and computes that list per request.

```php
AQL::FIELDS =>
[
    'narrower' => Filter::EDGES ,
],
AQL::EDGES =>
[
    'narrower' =>
    [
        AQL::MODEL     => TermNarrower::class ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::WHERE     => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,
    ] ,
],
```

The emitted AQL:

```aql
LET narrower_e1 = ( FOR vertex_1, edge_1 IN OUTBOUND doc term_narrower
                      OPTIONS { … }
                      FILTER vertex_1.id NOT IN @hiddenTerms
                      RETURN vertex_1 )
```

The `FILTER` targets `vertex_1`, the **arrival** vertex — not `doc`. The value of `@hiddenTerms` is supplied at call time through `Arango::BINDS`.

### The grammar is one you already know

`AQL::WHERE` compiles with the **same compiler** as `Field::WHERE` and `Field::WHEN` (see [Conditional fields](db/conditional-fields.md)). Anything one accepts, the other accepts:

```php
AQL::WHERE => 'active' ,                                        // TO_BOOL(vertex.active)
AQL::WHERE => [ 'status' , 'active' ] ,                         // vertex.status == 'active'
AQL::WHERE => [ 'or' , [ 'status' , 'active' ] , [ 'not' , [ 'hidden' , true ] ] ] ,
AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] ,       // vertex.id NOT IN @hidden
```

> **Two keys of the same value, two seats.** `Field::WHERE` is declared on a **projection entry** and filters the elements of an embedded array (`Filter::MAP`); `AQL::WHERE` is declared on a **relation definition** and filters the traversed vertices. The pair mirrors `Field::REQUIRES` (entry) and `AQL::REQUIRES` (definition) exactly — one grammar, two places to put it.

### What the key guarantees

| Situation | Behaviour |
|---|---|
| Key absent | **No `FILTER` emitted** — byte-for-byte identical AQL |
| Bind bound to `[]` | `IN []` ⇒ no vertex retained |
| Bind not supplied at call time | The query **fails** — never "no filter" |
| Malformed descriptor | Exception at build time — never a silently missing filter |
| Unsafe attribute name | `ValidationException` (`assertAttributeName`) |

Fail-closed is native: a wiring omission shows up, it does not open up.

### Scope

| Form | Supported |
|---|---|
| `Filter::EDGES` (cardinality N) | ✅ |
| `Filter::EDGE` (cardinality 1) | ✅ — same definition, same predicate |
| `Filter::EDGES_COUNT` | ✅ — **the count filters too** |
| Nested relations (`AQL::EDGES` inside a definition) | ✅ at any depth, each level its own predicate |
| Polymorphic edge | ✅ **per branch** (`Arango::MAP` and `Arango::FALLBACK`) |
| Joins (`Filter::JOIN` / `JOINS`) | ⛔ — see `AQL::CONDITIONS` on the join definition |

> **The count filters, and that is not negotiable.** A count ignoring the predicate would announce "5" beside a list of 3. The divergence *is* the bug, not the filtering: `Filter::EDGES_COUNT` reads the same declaration and emits its `FILTER` inside the counted loop.

> **On a polymorphic edge the key is declared branch by branch** — and that is the right granularity: each branch traverses a **different collection**, so the masked set is not the same. The predicate is **appended** to the discriminator guard, never substituted for it: `FILTER doc.kind == "warehouse" && vertex_1.id NOT IN @hidden`. Losing the guard would make every branch of the `APPEND` yield rows at once.

### On a hierarchy, filtering is not enough — `AQL::PRUNE`

**The situation.** A relation can climb several levels at once (`AQL::MAX_DEPTH`): the whole descent of a category, not just its direct children. Take this tree, where `b` is masked:

```
a ─┬─ b ── c
   └─ e ── f
```

`AQL::WHERE` filters the traversal's **output**; it does not stop the walk. The server therefore descends *through* `b` — which it then discards — and still brings back `c`. **Measured against a real server**:

| Declaration | Result |
|---|---|
| `AQL::WHERE` alone | `c`, `e`, `f` — **`c` leaks** |
| `AQL::WHERE` + `AQL::PRUNE => true` | `e`, `f` — branch cut, sibling intact |

The signpost by the road was removed, but the road stayed open.

**The declaration.** `AQL::PRUNE => true` reuses the `AQL::WHERE` predicate and **negates** it — "hide it, *and* its descent":

```php
'descendants' =>
[
    AQL::MODEL     => 'TermNarrowerEdge' ,
    AQL::MAX_DEPTH => 5 ,
    AQL::WHERE     => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,
    AQL::PRUNE     => true ,
] ,
```

```aql
(FOR vertex_1, edge_1 IN 1..5 OUTBOUND doc term_narrower
   PRUNE !(vertex_1.id NOT IN @hiddenTerms)
   OPTIONS { order: "bfs", uniqueVertices: "global" }
   FILTER vertex_1.id NOT IN @hiddenTerms
   SORT edge_1.created DESC RETURN vertex_1)
```

> **The two clauses go TOGETHER — neither replaces the other.** `PRUNE` stops the walk *after* visiting the vertex, so the one it stops on is **still returned**; it is the `FILTER` that removes it. Emitting `PRUNE` alone would hide the descent but keep the masked vertex.

**A condition of its own**, for when "stop descending" is not "hide":

```php
AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,   // what is hidden
AQL::PRUNE => [ 'archived' , true ] ,                            // where we stop
```

`AQL::PRUNE` may also be declared **alone**: the walk stops, nothing is hidden — the vertex it stops on stays in the result, its descent does not.

**Edge cases:**

| Case | Behaviour |
|---|---|
| Key absent | No `PRUNE` emitted, query byte-for-byte unchanged |
| `true` without `AQL::WHERE` | **Exception** — there is nothing to negate, so it is a wiring error. Staying silent would leave the masked descent in the result, which is exactly what the key exists to prevent. |
| Malformed condition | Exception at build time, like `AQL::WHERE` |
| Depth-1 relation | Accepted, no effect — a definition may gain depth later |
| `Filter::EDGES_COUNT` | **Not concerned**: the count is always a depth-1 traversal (it does not emit the declared range), and pruning a single-level walk changes nothing. ⚠️ Should the count ever honour the depth range, it must honour `AQL::PRUNE` at the same time. |

> **A bonus: the reconstructed tree becomes consistent again.** With `AQL::WITH_PATH`, `c` arrived announcing "my parent is `b`" — a parent absent from the result, which `buildTree()` had nowhere to attach. If `c` no longer comes, the problem disappears.

> Tested **against a real server** (`EdgePruneScopeIntegrationTest`), not only on the rendered AQL: the guarantee depends on how the server walks the graph, notably with the options the library always emits (`order: bfs`, `uniqueVertices: global`).

### `AQL::WHERE` and the permission gates answer different questions

This is the distinction to keep in mind:

- `AQL::REQUIRES` / `Field::REQUIRES` decide **IF** the relation is projected. Denied ⇒ the relation disappears entirely, key absent from the JSON and `LET` absent from the AQL.
- `AQL::WHERE` decides **WHICH** vertices it yields. The relation is there, its content is restricted.

The two compose without stepping on each other: denied, there is no traversal left to restrict; granted, the traversal is there and it is restricted.

### Host wiring

Nothing to wire in the library. Two things to do in the consumer project:

1. **Resolve the list** (often a cached resolver — see `DocumentFieldSetResolver`).
2. **Inject the bind** into `Arango::BINDS` when calling the model.

> **The orphan bind is pruned on its own.** A relation is projected conditionally: a skin can drop it, and the declared bind would then be referenced nowhere — ArangoDB would reject the whole query. The execution layer removes that surplus automatically, deriving the prunable bind list from the model's declarations, **relation registries included**. Details in [Conditional fields](db/conditional-fields.md#skinned-field-the-orphan-bind-is-pruned-automatically).

## The count always says what the list shows — `Filter::EDGES_COUNT`

Beside a list of relations you often display a plain **number**: "this category has N sub-categories". That is a count (`Filter::EDGES_COUNT`), and its whole point is to know the size without loading the list.

### How it is declared

The count and the list talk about the **same link**, so they share the **same definition**. The registry has a shortcut for saying that: a string value dereferences to another entry.

```php
AQL::FIELDS =>
[
    'id'               => [] ,
    'descendants'      => [ Field::FILTER => Filter::EDGES       ] ,  // the list
    'descendantsCount' => [ Field::FILTER => Filter::EDGES_COUNT ] ,  // the number
] ,

AQL::EDGES =>
[
    'descendants' =>
    [
        AQL::MODEL     => 'NarrowerEdge' ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        AQL::MAX_DEPTH => 5 ,
    ] ,
    'descendantsCount' => 'descendants' ,   // ← the shortcut: THE SAME definition
] ,
```

> Nothing forces the shortcut — you may declare a full definition under `descendantsCount`. But then the two entries are independent: keeping them consistent is on you, since the library cannot guess you meant them to describe the same link.

### The rule, in one sentence

**The count counts exactly the rows the list returns.** Everything the definition says about *which* vertices are walked — the depth, the row scope (`AQL::WHERE`), the stop condition (`AQL::PRUNE`), vertex uniqueness — is read the same way on both sides.

That is not a free platitude: it was not true. Three declarations produced a number the rows contradicted, and **none of the three is visible in the AQL text** — they depend on how the server walks the graph. Hence live tests.

### The three cases, measured

Take this tree, with two deliberate subtleties: `d` is reachable by **two paths**, and the `a → c` link exists **twice**.

```
a ─┬─ b ── d
   ├─ c ── d
   └─ c          ← the same link, created twice
```

| Declaration | The list returns | The count used to say | It says |
|---|---|---|---|
| No depth, but a duplicated link | `[b, c]` → **2** | **3** — `c` counted twice | **2** ✅ |
| `AQL::MAX_DEPTH => 5` | `[b, c, d]` → **3** | **2** — direct children only | **3** ✅ |
| `MIN_DEPTH => 2` + `MAX_DEPTH => 5` | `[d]` → **1** | **2** — lower bound ignored | **1** ✅ |

The three causes, one at a time:

1. **The depth was not read.** The count emitted neither `AQL::MIN_DEPTH` nor `AQL::MAX_DEPTH`, so it stayed one level deep while the list descended five. On the tree above: `2` beside a list of `3`.
2. **The traversal options were not the same.** The list has always walked with `uniqueVertices: global` — each vertex visited **once**, however many paths lead to it. The count emitted **no** options, so ArangoDB's default applied: one row per path. A duplicated link, or a diamond, made it over-count. This is the one case that **already affected depth-1 relations**.
3. **The stop condition was not read** — a consequence of point 1. As soon as the count walks deep, it must stop where the list stops, or it counts the descent of a vertex the list cut off.

> ⚠️ **The first two causes partly cancelled each other out, which is what made the bug hard to see.** On a ranged relation, missing the range *under*-counts while missing the options *over*-counts. Fixing the depth alone would have traded a wrong `2` for a wrong `6` (measured). Both were needed.

### The emitted AQL

```aql
LET descendantsCount = (LENGTH(FOR descendantsCount_v IN 1..5 OUTBOUND doc term_narrower
                                 PRUNE !(descendantsCount_v.id NOT IN @hidden)
                                 OPTIONS { order: "bfs", uniqueVertices: "global" }
                                 FILTER descendantsCount_v.id NOT IN @hidden
                                 RETURN descendantsCount_v))
```

Word for word the list's traversal, minus the projection and the sort — neither of which changes *how many* rows there are.

### What did not change

| | |
|---|---|
| A definition **without** depth, scope or prune | Still counts the direct neighbours |
| The inner loop variable | Still derived from the `LET` name (`<name>_v`), never `vertex` — otherwise it collides when the count is projected through a traversal |
| Permissions | Unchanged: `Field::REQUIRES` on the count entry, or `AQL::REQUIRES` on the shared definition, decide **whether** the count is emitted |
| The polymorphic count | Still unsupported (see above) |

> **Only one thing moves in the AQL of an existing definition**: the traversal options now appear in the count. Concretely, a vertex reachable more than once is no longer counted more than once. A screen showing a number inflated by a duplicated link will now show the right one.

> All three cases are verified **against a real server** (`EdgeCountAgreesWithListIntegrationTest`): the list and the count are produced by the real builders, executed in the same query, and compared against each other.

### Under the hood: three shared doors

So the divergence cannot come back, both builders read the definition through the **same** functions rather than each in its own way:

| Door | What it reads |
|---|---|
| `resolveEdgeDepthRange()` | The `MIN_DEPTH` / `MAX_DEPTH` pair, **and its refusal rule** (`MIN_DEPTH` alone is forbidden) |
| `resolveEdgeVertexScope()` | `AQL::WHERE` and `AQL::PRUNE`, compiled against the vertex variable it is handed |
| `edgeTraversalOptions()` | The traversal options |

Not cosmetic: the refusal rule, for instance, now holds on both sides — the count and the list can no longer disagree about what counts as a valid declaration.

## Anchoring a relation elsewhere — `Arango::SOURCE`

By default, a relation reads its anchor **from its own output name**: a join named `provider` looks for its foreign key in `doc.provider`, an edge named `supplier` traverses from `doc` itself. The output label and the place the data actually lives are welded together.

`Arango::SOURCE` **unwelds them**: it declares, as an **absolute path from `doc`**, *where* the relation reads its anchor — independently of the output field name. It is **opt-in**: absent, the AQL is byte-identical.

What the anchor *is* differs by relation type — the two mechanisms do not hook onto the same thing:

- **Join** — the anchor is the **foreign key value** compared to `doc_join._key`. `SOURCE` moves the match: `FILTER doc_join._key == doc.<source>`.
- **Edge** — the anchor is the **start vertex** of the traversal. `SOURCE` moves the departure: `FOR … IN OUTBOUND doc.<source> …`.

### On a join

**The situation.** An `offer` document stores its provider id in a `selector` sub-object, but you want to expose it flat under `provider`.

```php
AQL::FIELDS =>
[
    'provider' => Filter::JOIN ,
],
AQL::JOINS =>
[
    'provider' =>
    [
        AQL::MODEL     => Models::PROVIDER ,
        Arango::SOURCE => 'selector.providerId' ,   // absolute path, decoupled from "provider"
        AQL::FIELDS    => [ '_key' => Filter::DEFAULT , 'name' => Filter::DEFAULT ] ,
    ],
],
```

Generated AQL (simplified):

```aql
LET provider = (
    FOR doc_join IN @@provider
        FILTER doc_join._key == doc.selector.providerId
        RETURN { _key: doc_join._key, name: doc_join.name }
)
```

Without `SOURCE`, the join would target `doc.provider` — which does not exist → an empty join.

### On an edge

**The situation.** You want to follow the `supplied_by` edges not from the current document, but from the vertex whose id is stored at `doc.selector.providerId`.

```php
AQL::EDGES =>
[
    'supplier' =>
    [
        AQL::MODEL     => EdgesDefinition::SUPPLIED_BY ,
        AQL::DIRECTION => Traversal::OUTBOUND ,
        Arango::SOURCE => 'selector.providerId' ,   // the traversal start vertex
    ],
],
```

Generated AQL (simplified):

```aql
LET supplier = (
    FOR vertex, edge IN OUTBOUND doc.selector.providerId supplied_by
        RETURN vertex
)
```

> ⚠️ **On an edge, `doc.<source>` must hold a full `_id`** (`"providers/123"`), not a bare `_key`: ArangoDB starts a traversal from a vertex `_id`. On a join, `doc.<source>` holds the key compared to `doc_join._key`. Same unifying idea — *where to read the anchor on the parent* — different anchor nature.

> **`SOURCE` composes with `PROPERTY`.** `SOURCE` fixes the root, `PROPERTY` stays a relative suffix: `Arango::SOURCE => 'selector.provider'` + `Arango::PROPERTY => 'id'` → `doc.selector.provider.id`. The historical `substitutesSegment` pattern (`PROPERTY` alone, no `SOURCE`) is unchanged.

> **On a polymorphic relation, only the anchor moves.** For a polymorphic edge, `SOURCE` moves the traversal start (`OUTBOUND doc.<source>`) while the **discriminator stays resolved on the parent document** (`doc.<discriminator>` still selects the edge collection) — the two references are intentionally distinct.

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

