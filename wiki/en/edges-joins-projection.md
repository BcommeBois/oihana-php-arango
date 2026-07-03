# AQL edge and join projection

## Table of contents

1. [Overview](#overview)
2. [The `Field::SKINS` marker at the document level](#the-fieldskins-marker-at-the-document-level)
3. [Composed projection — `AQL::FIELDS` + `AQL::EDGES` on the edge definition](#composed-projection--aqlfields--aqledges-on-the-edge-definition)
4. [Hierarchical traversal — `AQL::MAX_DEPTH` / `AQL::MIN_DEPTH`](#hierarchical-traversal--aqlmax_depth--aqlmin_depth)
5. [Projecting edge properties — `Field::SCOPE`](#projecting-edge-properties--fieldscope)
6. [Wrapping the reference under a key — `Filter::WRAP`](#wrapping-the-reference-under-a-key--filterwrap)
7. [Projecting a *join* — `Filter::JOIN` / `Filter::JOINS`](#projecting-a-join--filterjoin--filterjoins)
8. [Breaking an INBOUND cycle with `AQL::SKIN`](#breaking-an-inbound-cycle-with-aqlskin)
9. [Per-request projection — `Field::SKINS` on sub-fields](#per-request-projection--fieldskins-on-sub-fields)
10. [Alternative projection per skin — `AQL::SKIN_FIELDS`](#alternative-projection-per-skin--aqlskin_fields)
11. [Which mechanism to use?](#which-mechanism-to-use)
12. [Permission-gated edges and joins — `AQL::REQUIRES`](#permission-gated-edges-and-joins--aqlrequires)
13. [Transforming the projected value — `Field::ALTERS`](#transforming-the-projected-value--fieldalters)
14. [Internal reference — the `matchesSkin` helper](#internal-reference--the-matchesskin-helper)

## Overview

The AQL projection layer decides, for each HTTP request, which fields and which relations (edges, joins) to include in the response. The decision relies on three building blocks:

- the **request skin**: passed via `?skin=full`, `?skin=default`, or injected by the controller through `SKIN_METHODS` (defaulting to `default` for a list, `full` for a single GET);
- the **`Field::SKINS` markers** on the fields: declare the skins that activate the field;
- the **edge or join definition** in `AQL::EDGES` / `AQL::JOINS`: declares the projection of related documents.

The internal flow:

```
controller → model->get/list( SKIN ) → returnFields( $init )
   → prepareQueryFields( fields , skin )
      → filterFieldsBySkin( fields , skin )   ← matchesSkin against Field::SKINS
   → buildVariables( fields , edges , joins )
      → buildEdgeVariable( definition )       ← edge projection
      → buildJoinVariable( definition )       ← join projection
```

You never call `matchesSkin` or the builders directly. You declare your intent through `Field::SKINS`, `AQL::FIELDS`, `AQL::EDGES`, `AQL::SKIN`, `AQL::SKIN_FIELDS` in the container definitions.

## The `Field::SKINS` marker at the document level

On a `Documents` model field, `Field::SKINS` declares the list of skins that activate the field.

```php
AQL::FIELDS =>
[
    Prop::_KEY        => Filter::DEFAULT ,
    Prop::EMAIL       => Filter::DEFAULT ,
    Prop::ROLES_COUNT => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
    Prop::ROLES       => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::DEFAULT , Skin::FULL ] ] ,
    Prop::PERMISSIONS => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL ] ] ,
]
```

Result:

- `GET /users` (default skin `default`) returns `_key`, `email`, `rolesCount` and `roles[]`.
- `GET /users/{id}` (default skin `full`) returns `_key`, `email`, `roles[]` and `permissions[]` (the count drops).

A field without a `Field::SKINS` marker is always visible.

The marker accepts three shapes:

```php
Field::SKINS => [ Skin::FULL , Skin::DEFAULT ]   // array of skins
Field::SKINS => 'main,full'                       // comma-separated string
Field::SKINS => null                              // equivalent to no marker
```

Skins are opaque strings. Any skin defined in `Acme\enums\Skin` (which extends the `oihana\controllers\enums\traits\SkinTrait` trait) can be used freely, including business skins like `Skin::IMAGE`, `Skin::OFFERS`, `Skin::EMPLOYEE`.

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

Useful options on the join definition: `Arango::KEY` (join attribute, default `_key`), `Arango::PROPERTY` (point at a nested parent property as the key), `Arango::CONDITIONS` (extra filters), nested `AQL::FIELDS` / `AQL::EDGES` / `AQL::JOINS`, `AQL::SKIN` / `AQL::SKIN_FIELDS` (the joined projection varies with `?skin=`), `AQL::REQUIRES` ([permission gating](#permission-gated-edges-and-joins--aqlrequires)).

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

## Per-request projection — `Field::SKINS` on sub-fields

When the projection of an edge varies only slightly between skins, the lightest path is to put `Field::SKINS` on the sub-fields of the projection. The request skin is propagated automatically to the target through `$init` (parent-skin inheritance) or can be pinned explicitly via `AQL::SKIN`.

Example: on `/users`, you want flat roles in the list and rich roles on the single fiche. Without duplicating the definition:

```php
// users.php
Prop::ROLES =>
[
    AQL::MODEL  => EdgesDefinition::USER_HAS_ROLES ,
    AQL::FIELDS =>
    [
        // Flat fields — visible in every skin (no marker)
        Prop::_KEY                        => Filter::DEFAULT ,
        Prop::NAME                        => Filter::DEFAULT ,
        Prop::IDENTIFIER                  => Filter::DEFAULT ,

        // Counts only on the list, hydrated relations only on the single fiche
        Prop::PERMISSIONS_COUNT           => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::PERMISSIONS                 => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
        Prop::APPLICATION_TEMPLATES_COUNT => [ Field::FILTER => Filter::EDGES_COUNT , Field::SKINS => [ Skin::DEFAULT ] ] ,
        Prop::APPLICATION_TEMPLATES       => [ Field::FILTER => Filter::EDGES       , Field::SKINS => [ Skin::FULL    ] ] ,
    ] ,
    AQL::EDGES =>
    [
        Prop::PERMISSIONS_COUNT           => Prop::PERMISSIONS ,
        Prop::PERMISSIONS                 => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_PERMISSIONS ] ,
        Prop::APPLICATION_TEMPLATES_COUNT => Prop::APPLICATION_TEMPLATES ,
        Prop::APPLICATION_TEMPLATES       => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_APPLICATION_TEMPLATES ] ,
    ] ,
]
```

Outcomes:

- `GET /users` (skin `default`): each role exposes its flat fields, plus `permissionsCount`;
- `GET /users/{id}?skin=full` or `GET /me`: each role additionally exposes hydrated `permissions[]`.

The same definition covers both cases. Dedicated sub-endpoints (`/users/{id}/roles`, `/users/{id}/permissions/effective`) have their own DI and stay rich independently.

### `Field::SKINS` in depth — nested sub-fields (`Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP`)

The `Field::SKINS` marker is honored at **every nesting level** of a projection: on the sub-fields of a `Filter::MAP`, a `Filter::DOCUMENT` or a `Filter::WRAP` — a MAP inside a MAP included. The request skin is propagated to the nested `Field::FIELDS`, with the same rules as on the first level:

- a sub-field **without** a marker is visible in every skin;
- when no skin is requested, everything passes;
- a filtered sub-field disappears **entirely**: its key is absent from the response and, when it carries a relation marker (with its `Field::EDGES` / `Field::JOINS` entry at the same level), the matching `LET` is not emitted.

Example: a product stores a price grid `offers[]`, each entry containing a nested `offers[]` sub-array (one price per customer type). Each price carries a sensitive `priceSpecification` breakdown that must only appear in dedicated skins — with a single declaration of the field:

```php
'offers' =>
[
    Field::FILTER => Filter::MAP ,
    Field::FIELDS =>
    [
        'offers' =>
        [
            Field::FILTER => Filter::MAP ,
            Field::FIELDS =>
            [
                'price'              => Filter::DEFAULT ,
                'priceSpecification' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'offers.full' , 'full' ] ] ,
            ] ,
        ] ,
    ] ,
]
```

Outcomes:

- `GET /products/{id}` (skin `default`): the grid comes out with `price`, without `priceSpecification`;
- `GET /products/{id}?skin=full`: the `priceSpecification` breakdown appears on every price.

**Emptied parent = dropped parent.** When the skin removes **all** the declared sub-fields of a MAP / DOCUMENT / WRAP parent, the parent itself disappears from the projection (key absent) — never a raw sub-document fallback, never an empty object, never an error. This is the natural skin semantics: a field outside the skin does not appear.

**Cohabitation with `Field::REQUIRES`.** Both markers compose on the same sub-field: `Field::SKINS` decides the **view** (the requested skin), `Field::REQUIRES` the **security** (the permission). The sub-field only appears when the skin matches **and** the permission is granted.

## Alternative projection per skin — `AQL::SKIN_FIELDS`

When the projection differs broadly between skins and putting `Field::SKINS` everywhere would hurt readability, declare distinct projections via `AQL::SKIN_FIELDS`: a `skin => projection` table where each projection is a fields array of the **same shape as `AQL::FIELDS`**. When building the sub-query, the framework picks the bucket matching the request skin.

```php
AQL::EDGES =>
[
    Prop::ROLES =>
    [
        AQL::MODEL       => EdgesDefinition::USER_HAS_ROLES ,
        AQL::SKIN_FIELDS =>
        [
            // Flat version (skin `default`, the list): scalar fields only
            Skin::DEFAULT =>
            [
                Prop::_KEY => Filter::DEFAULT ,
                Prop::NAME => Filter::DEFAULT ,
            ] ,

            // Rich version (skin `full`, the single fiche): same fields + a hydrated relation
            Skin::FULL =>
            [
                Prop::_KEY        => Filter::DEFAULT ,
                Prop::NAME        => Filter::DEFAULT ,
                Prop::PERMISSIONS => Filter::EDGES ,
            ] ,

            // Optional: fallback bucket for any other skin
            '*' =>
            [
                Prop::_KEY => Filter::DEFAULT ,
                Prop::NAME => Filter::DEFAULT ,
            ] ,
        ] ,
        AQL::EDGES =>
        [
            Prop::PERMISSIONS => [ AQL::MODEL => EdgesDefinition::ROLE_HAS_PERMISSIONS ] ,
        ] ,
    ] ,
]
```

Each bucket is a **complete, standalone** projection: the selected bucket fully replaces the others, there is **no merging** between buckets (hence the repeated `_key`/`name` — see the factoring below). The `Filter::EDGES` marker in the `full` bucket relies on the definition's `AQL::EDGES` entry, exactly like in a classic `AQL::FIELDS` projection; since the `default` bucket does not carry the marker, the sub-traversal is not emitted for that skin.

Internal resolution order:

1. `AQL::SKIN_FIELDS[$skin]` — explicit projection for the active skin;
2. `AQL::SKIN_FIELDS['*']` — fallback bucket within the table;
3. `AQL::FIELDS` — legacy single projection (backwards compatibility);
4. `null` — no projection declared.

If `AQL::SKIN_FIELDS` is absent or not an array, the resolution falls back directly on `AQL::FIELDS`, which guarantees backwards compatibility with pre-existing definitions.

`AQL::SKIN_FIELDS` is also recognised by `buildJoinVariable`; the mechanism is strictly the same for joins.

### Factoring the buckets with a projection function

Buckets often share a common base; writing it in every bucket is tedious and drift-prone (a field added in one bucket and forgotten in another). The usual pattern is a **projection function** on the host-project side: a plain helper returning the base and merging extras into it.

```php
/**
 * Base projection of a role; $extra adds (or overrides) fields per bucket.
 */
function role( array $extra = [] ) :array
{
    return
    [
        Prop::_KEY => Filter::DEFAULT ,
        Prop::NAME => Filter::DEFAULT ,
        ...$extra ,
    ] ;
}
```

The table from the previous example becomes compact:

```php
AQL::SKIN_FIELDS =>
[
    Skin::DEFAULT => role() ,                                       // flat version: the base alone
    Skin::FULL    => role([ Prop::PERMISSIONS => Filter::EDGES ]) , // base + hydrated relation
    '*'           => role() ,                                       // optional: fallback
] ,
```

This helper belongs to the **host project** — it does not exist in the library; it is a configuration convention, not an API. It pays off as soon as several buckets (or several edge/join definitions targeting the same model) share the same field base.

### Scope of `AQL::SKIN_FIELDS`

Two limits worth knowing:

- `AQL::SKIN_FIELDS` is an **edge or join definition key** — that is the only place where it is read (and it is re-resolved at every relation nesting level, the request skin being propagated). Placed on a model field (root `AQL::FIELDS`) or on a sub-field of a `Filter::MAP` / `Filter::DOCUMENT` / `Filter::WRAP`, it is **silently ignored**. To vary a field or a sub-field with the skin, the mechanism is [`Field::SKINS`](#per-request-projection--fieldskins-on-sub-fields), honored at every depth.
- A skin pinned via `AQL::SKIN` only applies to the definition carrying it: its nested relations (nested `AQL::EDGES` / `AQL::JOINS`) fall back on the request skin, unless explicitly pinned on their own definition.

## Which mechanism to use?

| Need | Recommended solution |
|---|---|
| A single projection regardless of the skin | `AQL::FIELDS` alone |
| A few sub-fields vary between skins (count hidden on full, edge hidden on default…) | `Field::SKINS` on the sub-fields of `AQL::FIELDS` |
| A **nested** sub-field (price grid, sub-object of a MAP/DOCUMENT/WRAP) must only appear in some skins | `Field::SKINS` on the nested sub-field — honored at every depth |
| The projection differs broadly between skins (added fields, swapped joins…) | `AQL::SKIN_FIELDS` with one entry per skin |
| INBOUND edge towards a document that may reference back to the source | `AQL::SKIN => Skin::MAIN` on the edge definition to break the cycle |
| Restrict an edge or join projection to a user permission | `AQL::REQUIRES` on the definition + callable injection via `InjectAuthorizerTrait` |

The mechanisms compose. A definition can combine `AQL::SKIN_FIELDS` for the main projection, `Field::SKINS` on the sub-fields of each individual projection, and an `AQL::SKIN` to pin the target skin. The resolution is independent at each level.

## Permission-gated edges and joins — `AQL::REQUIRES`

A definition can declare a required permission via `AQL::REQUIRES`. When the current user does not hold that permission, the edge or join is silently dropped from the projection (no `LET` is emitted, no leak, no error). The mechanism stays agnostic of the underlying authorization layer: the decision is delegated to a callable injected through `$init[Arango::AUTHORIZER]`.

### Declaration shape

```php
Prop::ROLES =>
[
    AQL::MODEL    => EdgesDefinition::USER_HAS_ROLES ,
    AQL::REQUIRES => 'users.roles:list' ,
] ,
```

`AQL::REQUIRES` accepts two shapes:

- **A string** — a single required permission subject.
- **An array of strings** — OR semantics: the projection is allowed as soon as **at least one** of the subjects is granted. Useful when several permissions can open the same edge (for instance `users.roles:list` or `users.roles:admin`).

When `AQL::REQUIRES` is absent, no check is performed — default behaviour, no risk for existing definitions.

### Wiring on the controller side — recommended pattern

`oihana/php-arango` knows nothing of the authorization layer in use (Casbin, OPA, custom, ...). The controller provides a `Closure(string $subject): bool` that the framework will call for every declared subject.

`DocumentsController` exposes two lifecycle hooks from [`ModelCallTrait`](https://github.com/BcommeBois/oihana-php-system/blob/main/src/oihana/controllers/traits/ModelCallTrait.php) — `beforeModelCall( ?Request , array &$init )` and `afterModelCall( ?Request , array &$init , mixed &$result )` — automatically invoked around every primary CRUD operation (`list`, `get`, `last`, `count`, `insert`, `update`, `replace`, `delete`). The recommended pattern is to override `beforeModelCall` once to enable access control on every HTTP verb of the controller:

```php
use oihana\api\controllers\traits\CapabilityAuthorizerTrait;
use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;

use Psr\Http\Message\ServerRequestInterface as Request;

final class UsersController extends DocumentsController
{
    use CapabilityAuthorizerTrait ;

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;

        if ( ( $authorizer = $this->buildAuthorizer( $request ) ) !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }
    }
}
```

`CapabilityAuthorizerTrait` — bundled in the `CapabilityGuardTrait` facade — builds a request-scoped `Closure(string): bool` against the Casbin `CapabilityEnforcer` and the current Zitadel `userId`. It applies `safeSubject` automatically (see [auth code tips](https://github.com/BcommeBois/oihana-php-auth/blob/main/wiki/en/tips.md)). When the enforcer is unavailable or the request carries no authenticated user, `buildAuthorizer` returns `null` — the `if` short-circuits and the framework falls back on its default behaviour (fail open, see next section).

Benefit: the override is **a single line per controller**, not per HTTP verb. The wiring covers `list`, `get`, `last`, `count`, `insert`, `update`, `replace`, `delete` automatically.

### Variant — request-agnostic pattern with `InjectAuthorizerTrait`

When the callable is known at controller construction time (unit test, callable resolved straight from the DI container without depending on the request, CLI batch mode, ...), an alternative trait [`InjectAuthorizerTrait`](../../src/oihana/arango/controllers/traits/inject/InjectAuthorizerTrait.php) (on the `oihana/php-arango` side, agnostic of Casbin) lets a controller store a stable callable at construction and pose it on every `$init`:

```php
use oihana\arango\controllers\traits\inject\InjectAuthorizerTrait;

final class BatchController extends DocumentsController
{
    use InjectAuthorizerTrait ;

    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;
        $this->initializeArangoAuthorizer( $init , fn() : bool => true ) ;
    }

    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        parent::beforeModelCall( $request , $init ) ;
        $this->injectAuthorizer( $init ) ;
    }
}
```

`initializeArangoAuthorizer` accepts any standard PHP callable shape (Closure, invokable, `[obj, 'method']`, `'Class::method'`, fully-qualified function name — resolution goes through `oihana\core\callables\resolveCallable`). For Casbin + request-scoped use cases in production, prefer the `CapabilityAuthorizerTrait` pattern above.

### Behaviour when no authorizer is present

If `$init[Arango::AUTHORIZER]` is not set (the controller does not override `beforeModelCall`, or no enforcer is registered for that controller), the internal `isAuthorized` helper returns `true` by default — the projection is **allowed** (fail open). This avoids breaking a route when `AQL::REQUIRES` is added on a shared definition before every consuming controller has been wired up.

For strict gating, the `Authorized` middleware on the HTTP route (Casbin HTTP-permission level) must remain the primary envelope — `AQL::REQUIRES` is a **second layer** of access control inside the AQL projection, not a replacement.

### Internal helper — `isAuthorized`

`isAuthorized($definition, $init)` is used by `buildVariables` to decide whether to include each edge or join. Its signature and behaviour:

```php
function isAuthorized( array $definition , array $init = [] ) : bool
```

- No `AQL::REQUIRES` → `true` (no-op).
- No callable under `Arango::AUTHORIZER`, or non-callable value → `true` (fail open).
- A string or array → `true` as soon as **at least one** subject is granted by the callable. Only strict `true` counts as a grant (a truthy `1`, `'yes'`, etc. does not allow the projection).

The helper lives at `oihana\arango\models\helpers\isAuthorized`.

## Transforming the projected value — `Field::ALTERS`

`Field::ALTERS` applies an **AQL transformation chain** to a field's value at **`RETURN` time**, exactly like the filters' [`alt`](db/filter.md#alt-transformations) transformations — but on the **output** side. It is the projection counterpart: what `alt` does to compare (`LOWER(doc.x) == LOWER(@v)`), `ALTERS` does to return (`name: LOWER(doc.name)`).

The chain reuses the same vocabulary as `alt` (the `FilterFunction` registry):

- a **single function**: `'lower'` → `LOWER(doc.x)`;
- a **function chain**: `['trim','lower']` → `LOWER(TRIM(doc.x))` (applied left to right, the last one wraps);
- a **function with parameters**: `['substring', 0, 3]` → `SUBSTRING(doc.x, 0, 3)`;
- a **mixed chain**: bare functions and parameterized functions can be combined in the same list — `['trim', ['substring',0,3], 'lower']` → `LOWER(SUBSTRING(TRIM(doc.x), 0, 3))`.

### Declaration

```php
Arango::FIELDS =>
[
    // name returned normalized: trimmed and lower-cased
    'name'  => [ Field::ALTERS => [ 'trim' , 'lower' ] ] ,

    // an output alias (slug) computed from another field (title)
    'slug'  => [ Field::NAME => 'title' , Field::ALTERS => 'lower' ] ,

    // a code truncated to the first 3 characters
    'code'  => [ Field::NAME => 'reference' , Field::ALTERS => [ 'substring' , 0 , 3 ] ] ,
] ,
```

Produces the projection:

```aql
RETURN {
    name : LOWER(TRIM(doc.name)) ,
    slug : LOWER(doc.title) ,
    code : SUBSTRING(doc.reference, 0, 3)
}
```

### Worked examples

| Intent | Declaration | Projected AQL |
|---|---|---|
| Email normalized to lower case | `'email' => [ Field::ALTERS => 'lower' ]` | `email: LOWER(doc.email)` |
| Trimmed title | `'title' => [ Field::ALTERS => 'trim' ]` | `title: TRIM(doc.title)` |
| Lower-case slug from `title` | `'slug' => [ Field::NAME => 'title', Field::ALTERS => 'lower' ]` | `slug: LOWER(doc.title)` |
| Cleaned proper name | `'name' => [ Field::ALTERS => ['trim','lower'] ]` | `name: LOWER(TRIM(doc.name))` |
| Initials (3 chars) | `'code' => [ Field::ALTERS => ['substring',0,3] ]` | `code: SUBSTRING(doc.code,0,3)` |

On the data `{ name: "  Jean DUPONT  ", title: "Hello World" }`, the projection above returns `{ name: "jean dupont", slug: "hello world" }`.

### Scope and rules

- **Opt-in per field**: a field without `Field::ALTERS` is projected unchanged (no change to existing behaviour).
- **Default scalar projection only** (`key: doc.key`). On a field carrying a **typed `Field::FILTER`** (`BOOL`, `DATETIME`, `NUMBER`…) or a **structural** one (`EDGE`, `JOIN`, `MAP`, `DOCUMENT`…), `Field::ALTERS` is **ignored**: a scalar chain (`LOWER`, `TRIM`…) makes no sense on a sub-object or a type conversion. Use one **or** the other.
- **`Field::NAME`** selects the source attribute; the output key stays the one from the definition (handy to expose a transformed field under another name, e.g. `slug`).
- No injection risk: function names are **whitelisted** (`FilterFunction`) — an unknown function is a no-op.

## Internal reference — the `matchesSkin` helper

`matchesSkin($skins, $currentSkin)` is used internally by `FieldsTrait::filterFieldsBySkin` to evaluate the `Field::SKINS` markers. It is **not** part of the public API of the projection framework — you don't need to call it directly.

Its signature and behaviour, for reference:

```php
function matchesSkin( mixed $skins , ?string $currentSkin ) :bool
```

- `null` for either argument: always returns `true` (no filter).
- Array: `in_array($currentSkin, $skins, true)`.
- String: equivalent to a comma-separated array, whitespace-tolerant.
- Any other shape: returns `true` by default (defensive default for malformed definitions).

The helper lives at `oihana\arango\db\helpers\matchesSkin`.
