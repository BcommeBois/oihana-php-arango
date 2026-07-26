# Signals & cascade (model lifecycle)

Every write on a `Documents` or `Edges` model — `insert`, `update`, `replace`, `upsert`, `delete`, `truncate` — emits a pair of **signals**, one *before* and one *after* the operation. A listener connected to these signals can **inspect** the incoming data, **react** to the result, or trigger a **side effect**.

The most powerful built-in side effect is the **delete cascade**. Deleting a vertex document automatically removes its edges, and — if you declared it — **purges the documents on the other end** in the direction you choose (`INBOUND` / `OUTBOUND` / `BOTH`). This is how you empty other collections by deleting a single document, without writing any application code.

The second one is quieter but just as useful: **invalidating dependent caches**. A service holding state derived from a collection must forget it as soon as the collection moves; `Arango::INVALIDATES` declares that on the model instead of re-coding it around every write.

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
5. [Invalidating dependent caches (`Arango::INVALIDATES`)](#invalidating-dependent-caches-arangoinvalidates)
   - [The `Invalidable` contract](#the-invalidable-contract)
   - [`DocumentFieldSetResolver` — a cached set of values](#documentfieldsetresolver--a-cached-set-of-values)
   - [What is silently skipped](#what-is-silently-skipped)
6. [Pitfalls & guarantees](#pitfalls--guarantees)
7. [See also](#see-also)

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

## Invalidating dependent caches (`Arango::INVALIDATES`)

Some services hold state **derived** from a collection. A thesaurus exposes the list of its switched-off terms, which every listing must exclude; a directory exposes the keys of the suspended tenants. That set is small, it moves rarely, and a hot query needs it on every call — re-reading it each time costs one extra query per request. So it is resolved **once** and kept.

Which leaves the question that decides everything: **who tells the cache when the collection changes?** With no answer, a term switched back on this morning stays filtered out until the TTL expires, and nobody sees a thing — the failure is silent, which is the worst kind.

The usual answer is to decorate the model factory, by hand, to re-wire `invalidate()` onto every write. That is copy-paste, and its only failure mode is precisely forgetting it. `Arango::INVALIDATES` replaces it with a **declaration** on the model: the container ids of the services this collection feeds.

The situation. A `terms` model feeds the cache of the switched-off terms. The declaration sits in the `$init`, next to the model's other keys — there is **nothing else to write**:

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\Documents ;

$terms = new Documents( $container ,
[
    AQL::COLLECTION     => 'terms' ,
    Arango::INVALIDATES => [ 'thesaurus.disabledTerms' ] , // a bare string is accepted too
]) ;

$terms->update( [ Arango::DOC => [ 'disabled' => false ] , Arango::VALUE => 'term-42' ] ) ;
// → afterUpdate emitted → the container resolves 'thesaurus.disabledTerms' → invalidate()
//   The next read of the service rebuilds the set.
```

The wiring is **automatic**: `Documents` composes `InvalidatesOnWriteTrait` and calls `initializeInvalidations()` last in its constructor — after `initializeDocumentsMethods()`, which creates the signals it connects to. `Edges` inherits it. No subclass, no call to place.

**All three** write signals are wired — `afterInsert`, `afterUpdate`, `afterDelete` — to the same closure. An insert, an update and a delete all count as "the collection moved": a derived set has no reason to survive one more than another.

> **The container is queried at emission time, never at wiring time.** This is deliberate, and not a performance detail: the invalidated service usually depends on the very model invalidating it — `thesaurus.disabledTerms` reads the `terms` collection. Resolving it inside the model constructor would close the loop and blow the container up. By the time a write finally emits the signal, the model is fully built and the resolution is safe.

### The `Invalidable` contract

An invalidable service implements [`oihana\interfaces\Invalidable`](https://github.com/BcommeBois/oihana-php-core/blob/main/src/oihana/interfaces/Invalidable.php), a single method:

```php
interface Invalidable
{
    public function invalidate() : void ;
}
```

That is the whole coupling. The model doing the invalidating does **not** know what the service caches, nor where, nor how — only that it must be made to forget. Memcached, an in-memory array or a file: the producer of the change never needs to hear about it.

### `DocumentFieldSetResolver` — a cached set of values

The lib ships the most common implementation: [`DocumentFieldSetResolver`](../../src/oihana/arango/cache/DocumentFieldSetResolver.php) resolves — and caches in Memcached — **the set of values taken by one field** across the documents of a filtered collection.

```php
use oihana\arango\cache\DocumentFieldSetResolver ;

$disabledTerms = new DocumentFieldSetResolver
(
    model    : $terms ,
    cache    : $memcached ,
    cacheKey : 'thesaurus.disabledTerms' ,  // required, unique per resolver
    filter   : [ 'key' => 'disabled' , 'op' => 'eq' , 'val' => true ] ,
    field    : 'id' ,                       // default: DocumentFieldSetResolver::DEFAULT_FIELD
    ttl      : 3600                         // default: 1 hour
) ;

$disabledTerms->values() ; // ['t-12', 't-40'] — de-duplicated, re-indexed
```

Three design decisions are worth knowing, because they turn against you if you take them the other way round in a hand-rolled resolver.

**The native type of each value is preserved, never normalised.** AQL does not coerce across types: `5 NOT IN ["5"]` is **true**. A set whose values were all "cleanly" cast to strings would therefore filter nothing at all — no error, no log, not the faintest sign. A numeric `id` stays an integer, and a leading-zero code (`'0608'`) stays a string.

**A failed read yields an empty set.** An unreachable database must not translate into "everything is excluded". An empty set means "nothing matched", and the caller should then pose **no** predicate at all rather than an always-true `NOT IN []`. The failure is logged when a logger was given, but it does not propagate: a cache outage does not take the request down.

**The cache key is required, and unique per resolver.** Two resolvers behind one key would serve each other's set. There is no default value, precisely so none can be inherited by accident.

The TTL is not the refresh mechanism — it is a **safety net**. The normal path stays the signal-driven invalidation, which is immediate; the TTL bounds staleness in case an invalidation point was ever missed. A `ttl: 0` bypasses the cache entirely and re-reads on every call: handy for debugging, ruinous in production.

> **Memcached is shared by every worker**, so one invalidation reaches the whole fleet — not just the process that received the write.

### What is silently skipped

The wiring is **tolerant**: a dubious declaration is skipped, never fatal. An invalidation blowing up would turn a perfectly valid write into a 500.

| Declaration | Effect |
|---|---|
| `Arango::INVALIDATES` absent, `null`, `[]` | no signal connected — the model is untouched |
| A value that is neither an array nor a string (`42`, an object…) | treated as absent |
| A bare string (`'a.service'`) | accepted, normalized to `['a.service']` |
| A non-string id inside the array | skipped; the rest of the list still applies |
| An id unknown to the container | skipped; the rest of the list still applies |
| A resolved service not implementing `Invalidable` | skipped; the rest of the list still applies |

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
| **Invalidation is wired by default** | `Documents` calls `initializeInvalidations()` at the end of its constructor; with no `Arango::INVALIDATES` declared, nothing is connected and the model is unchanged. |
| **Invalidation does not follow the cascade** | It is wired to the writes of **the model declaring it**. A layer-2 purge emits the purged model's `afterDelete`: it is *that* model which must declare its own `Arango::INVALIDATES`. |
| **An invalidation never fails the write** | Unknown id, non-`Invalidable` service, malformed declaration: all silently skipped. The write itself was valid. |

## See also

- [`Documents` and `Edges` models](models.md) — trait architecture, `AQL::*` keys, *Lifecycle and hooks* section.
- [Edges and joins projection](edges-joins-projection.md) — `AQL::EDGES`, `AQL::JOINS`, read traversals.
- [Embedded array fields](db/arrays.md) — atomic mutations and their `*Update` signals.
- [Glossary](getting-started/glossary.md#cascade) — *Cascade* and *Signal* entries.
- [`DocumentFieldSetResolver`](../../src/oihana/arango/cache/DocumentFieldSetResolver.php) and [`InvalidatesOnWriteTrait`](../../src/oihana/arango/cache/InvalidatesOnWriteTrait.php) — the two bricks of the `oihana\arango\cache` namespace.
- [Dependencies](getting-started/dependencies.md#oihanaphp-signals) — the role of `oihana/php-signals`.
- Upstream: [Signals & notices (`oihana/php-models`)](https://github.com/BcommeBois/oihana-php-models/blob/main/wiki/en/signals-notices.md) — `Signal` / `Payload` primitives, adding signals to a model.
