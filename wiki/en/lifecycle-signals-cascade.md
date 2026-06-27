# Signals & cascade (model lifecycle)

Every write on a `Documents` or `Edges` model — `insert`, `update`, `replace`, `upsert`, `delete`, `truncate` — emits a pair of **signals**, one *before* and one *after* the operation. A listener connected to these signals can **inspect** the incoming data, **react** to the result, or trigger a **side effect**.

The most powerful built-in side effect is the **delete cascade**. Deleting a vertex document automatically removes its edges, and — if you declared it — **purges the documents on the other end** in the direction you choose (`INBOUND` / `OUTBOUND` / `BOTH`). This is how you empty other collections by deleting a single document, without writing any application code.

```
            emit                       emit
   ┌──────────────────┐      ┌──────────────────┐
   │   beforeDelete   │      │   afterDelete    │
   └────────┬─────────┘      └────────┬─────────┘
            │                         │
   ─────────▼─────────  delete()  ────▼──────────────►  time
            │                         │
   (inspect / refuse)        (react / edges CASCADE)
```

> **The generic mechanism** (the `Signal` / `Payload` primitives, adding signals to an arbitrary model) is documented upstream in `oihana/php-models` → [Signals & notices](https://github.com/BcommeBois/oihana-php-models/blob/main/wiki/en/signals-notices.md). This page focuses on **what the `Documents`/`Edges` models of `oihana/php-arango` do with them**: the signals they emit and the delete cascade.

## Table of contents

1. [The six lifecycle signals](#the-six-lifecycle-signals)
2. [Connecting a listener](#connecting-a-listener)
3. [The delete cascade](#the-delete-cascade)
   - [Layer 1 — automatic edge purge](#layer-1--automatic-edge-purge)
   - [Layer 2 — directional purge of linked documents (`Purge`)](#layer-2--directional-purge-of-linked-documents-purge)
4. [End-to-end example](#end-to-end-example)
5. [Pitfalls & guarantees](#pitfalls--guarantees)
6. [See also](#see-also)

## The six lifecycle signals

Each CRUD operation exposes two public `oihana\signals\Signal` properties — a `before*` and an `after*` — and carries a strongly typed **notice** (`oihana\models\notices\Before*` / `After*`) that bundles:

- `data` — the document(s) involved / the result;
- `target` — the model that emitted the signal;
- `context` — the call's `$init` array (skin, locale, filters… depending on the operation);
- `type` — the textual discriminant from `oihana\models\enums\NoticeType` (e.g. `'afterDelete'`).

| Operation | *before* signal → notice | *after* signal → notice |
|---|---|---|
| `insert()`   | `$beforeInsert` → `BeforeInsert`     | `$afterInsert` → `AfterInsert`     |
| `update()`   | `$beforeUpdate` → `BeforeUpdate`     | `$afterUpdate` → `AfterUpdate`     |
| `replace()`  | `$beforeReplace` → `BeforeReplace`   | `$afterReplace` → `AfterReplace`   |
| `upsert()`   | `$beforeUpsert` → `BeforeUpsert`     | `$afterUpsert` → `AfterUpsert`     |
| `delete()`   | `$beforeDelete` → `BeforeDelete`     | `$afterDelete` → `AfterDelete`     |
| `truncate()` | `$beforeTruncate` → `BeforeTruncate` | `$afterTruncate` → `AfterTruncate` |

> **The `truncate` notices carry no `data`.** A `truncate()` empties an entire collection: there is no single document involved. The constructor only accepts `target` and `context`.

> **Automatic initialization.** Unlike the upstream lib (where you must call `initialize*Signals()` by hand), the `Documents`/`Edges` models **initialize their six signals in the constructor** (via `initializeDocumentsMethods()`). You can therefore `connect()` right after instantiation, with no preliminary step.

> **Array writes emit `*Update`.** `arrayInsert` / `arrayRemove` / `arrayMove` / `arrayPurgeRef` emit `beforeUpdate` / `afterUpdate`, exactly like `update()` (see [Array fields](db/arrays.md#signals)). `arrayContains` is a read: no signal.

## Connecting a listener

A listener is any *callable* connected through `connect()`. It receives the notice and reads its public properties.

```php
use oihana\arango\enums\Arango ;
use oihana\arango\models\Documents ;
use oihana\models\notices\AfterDelete ;

$users = new Documents( $container , [ AQL::COLLECTION => 'users' , /* … */ ] ) ;

// No initialize*Signals() to call: the model already did it.
$users->afterDelete?->connect( function( AfterDelete $notice )
{
    // $notice->data    : the deleted document(s) (OLD), or null if nothing matched
    // $notice->target  : the emitting model ($users)
    // $notice->context : the $init array passed to delete()
    // $notice->type    : NoticeType::AFTER_DELETE ('afterDelete')

    $this->logger?->info( 'Deleted users: ' . json_encode( $notice->data ) ) ;
} ) ;

$users->delete( [ Arango::KEY => Schema::_KEY , Arango::VALUE => 'alice' ] ) ;
```

> **Priorities, single-shot listeners, cleanup.** `connect()` accepts a `priority` (highest runs first) and an `autoDisconnect` flag (removed after the first call). To tear everything down, `release*Signals()`. Details in the upstream [Signals & notices](https://github.com/BcommeBois/oihana-php-models/blob/main/wiki/en/signals-notices.md#priorities-one-shot-listeners-and-cleanup).

## The delete cascade

This is the framework's key side effect, and the answer to *"how do I automatically empty other collections/edges when I delete a document?"*

When you `delete()` a **vertex** (a `Documents` used as a graph vertex), its `afterDelete` signal is intercepted by the `Edges` models that reference it. The cascade proceeds in **two layers**:

### Layer 1 — automatic edge purge

**Always on, nothing to declare.** An `Edges` model receives its `from` (source, `_from`) and `to` (target, `_to`) vertices at construction. While wiring them (`initializeFrom()` / `initializeTo()`), the `Edges` **subscribes** to each vertex's `afterDelete` signal:

```php
// EdgesFromTrait::registerFrom() — automatic subscription
$this->from->afterDelete->connect( [ $this , 'onDeleteVertex' ] ) ;
```

When the vertex is deleted, `onDeleteVertex()` calls `deleteEdges()`, which removes **every edge touching that vertex** — on both the `_from` **and** the `_to` side. The result: no orphan edge survives. That is the referential-integrity guarantee, with no application code.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\Edges ;

$userHasRoles = new Edges( $container ,
[
    AQL::COLLECTION => 'user_has_roles' ,
    AQL::FROM       => $users ,   // source vertex
    AQL::TO         => $roles ,   // target vertex
]) ;

$users->delete( [ AQL::VALUE => 'alice' ] ) ;
// → every user_has_roles edge from (or pointing to) 'alice' is removed.
//   The linked 'roles' documents themselves stay intact (see layer 2).
```

### Layer 2 — directional purge of linked documents (`Purge`)

**Optional, declared per vertex.** Beyond removing the edge, you can delete the **document on the other end**. This is exactly "deleting X empties collection Y". You enable it with the `AQL::PURGE` key at the `Edges` construction, fed by the [`Purge`](../../src/oihana/arango/models/enums/Purge.php) enum:

| `AQL::PURGE` | Direction | Effect |
|---|---|---|
| `Purge::OUTBOUND` | you delete the **`from`** | also purges the linked **`to`** |
| `Purge::INBOUND`  | you delete the **`to`**   | also purges the linked **`from`** |
| `Purge::BOTH`     | either side                | purges the other end in both cases |
| *(absent / `null`)* | — | **no** vertex purge: only the edges go (layer 1) |

Diagram, using a `WebAPI` linked to `Permission` documents through edges:

```
[FROM: WebAPI] ──edge──> [TO: Permission]

OUTBOUND   delete WebAPI      → also removes the linked Permission
INBOUND    delete Permission  → also removes the linked WebAPI
BOTH       delete WebAPI      → removes the Permission
           delete Permission  → removes the WebAPI
null       delete WebAPI      → removes ONLY the edges; Permission untouched
```

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\Edges ;
use oihana\arango\models\enums\Purge ;

$apiHasPermissions = new Edges( $container ,
[
    AQL::COLLECTION => 'api_has_permissions' ,
    AQL::FROM       => $webAPI ,
    AQL::TO         => $permissions ,
    AQL::PURGE      => Purge::OUTBOUND ,   // deleting a WebAPI purges its Permission
]) ;

$webAPI->delete( [ AQL::VALUE => 'documents-api' ] ) ;
// 1) the api_has_permissions edges of 'documents-api' are removed (layer 1)
// 2) the Permission targeted by those edges are removed (layer 2, OUTBOUND)
```

> **The direction is resolved at delete time.** `onDeleteVertex()` compares the signal's `target` (the vertex actually deleted) to `from` / `to`, then applies the purge only in the authorized direction. A `Purge::OUTBOUND` therefore *never* purges the `from` when a `to` is deleted.

> **Vertex purge is recursive by construction.** It runs through a `delete()` on the other end's model — which in turn emits its own `afterDelete`. If that model is itself the source of further cascading edges, the deletion propagates. Beware purge **cycles** (see pitfalls).

## End-to-end example

An account (`accounts`) linked to sessions (`sessions`) through `account_has_session` edges, with `OUTBOUND` purge: deleting an account must wipe its sessions.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\Documents ;
use oihana\arango\models\Edges ;
use oihana\arango\models\enums\Purge ;
use oihana\models\notices\AfterDelete ;

$accounts = new Documents( $container , [ AQL::COLLECTION => 'accounts' , /* … */ ] ) ;
$sessions = new Documents( $container , [ AQL::COLLECTION => 'sessions' , /* … */ ] ) ;

$accountHasSession = new Edges( $container ,
[
    AQL::COLLECTION => 'account_has_session' ,
    AQL::FROM       => $accounts ,
    AQL::TO         => $sessions ,
    AQL::PURGE      => Purge::OUTBOUND ,
]) ;

// Optional observer: audit what goes.
$sessions->afterDelete?->connect(
    fn( AfterDelete $n ) => $logger->info( 'Purged sessions: ' . json_encode( $n->data ) )
) ;

$accounts->delete( [ AQL::VALUE => 'acc-42' ] ) ;
// Effect:
//   • account_has_session edges of 'acc-42' removed        (layer 1)
//   • targeted 'sessions' documents removed                (layer 2, OUTBOUND)
//   • $sessions afterDelete emitted → observer logs          (observable cascade)
```

## Pitfalls & guarantees

| Point | Keep in mind |
|---|---|
| **Purge `null` by default** | Without `AQL::PURGE`, only the edges are removed; the other-end vertices remain. This is the safe (fail-safe) behaviour. |
| **The cascade fires on a vertex `delete()`** | It is wired to `afterDelete`. An `update()` / `replace()` triggers **no** edge cascade. |
| **Wiring = `from` / `to` provided** | The subscription only exists if the `Edges` knows its vertices (`AQL::FROM` / `AQL::TO`). An `Edges` with no vertices purges nothing automatically. |
| **`target` decides the direction** | The directional purge relies on the vertex actually deleted. `OUTBOUND`/`INBOUND` are respected even when `from` and `to` point to the same collection. |
| **Purge cycles** | A purge triggers a `delete()` that re-emits `afterDelete`. Two models purging each other in `BOTH` may loop — declare the purge on a single side, or break the cycle. |
| **Performance** | The purge deletes in **bulk** through an AQL `REMOVE` query (no PHP loop document by document). |
| **`?->` on signals** | The models initialize their signals but always emit through `?->emit()`: if a signal was released (`release*Signals()`), the emission is simply skipped, never an error. |

## See also

- [`Documents` and `Edges` models](models.md) — trait architecture, `AQL::*` keys, *Lifecycle and hooks* section.
- [Edges and joins projection](edges-joins-projection.md) — `AQL::EDGES`, `AQL::JOINS`, read traversals.
- [Embedded array fields](db/arrays.md) — atomic mutations and their `*Update` signals.
- [Glossary](getting-started/glossary.md#cascade) — *Cascade* and *Signal* entries.
- [Dependencies](getting-started/dependencies.md#oihanaphp-signals) — the role of `oihana/php-signals`.
- Upstream: [Signals & notices (`oihana/php-models`)](https://github.com/BcommeBois/oihana-php-models/blob/main/wiki/en/signals-notices.md) — `Signal` / `Payload` primitives, adding signals to a model.
