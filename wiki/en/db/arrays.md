# Embedded array fields `AQL::ARRAYS`

> Manage an **array stored inside a document** (add, remove, move, edit, test) server-side, atomically, in a single AQL `UPDATE`.

The [`DocumentsArrayTrait`](../../../src/oihana/arango/models/traits/DocumentsArrayTrait.php) trait — composed by [`Documents`](../models.md) — exposes a small set of methods to mutate an embedded list field (e.g. `tracks`, `tags`, `hasPart`…) without fetching the array back into PHP. The behaviour of each field (ordering, uniqueness, optional length counter) is declared **once** on the model, through the `AQL::ARRAYS` option.

This page documents:

1. [When to use it](#when-to-use-it) (vs *edges*).
2. The [`AQL::ARRAYS` declaration](#the-aqlarrays-declaration) and the [ordering modes](#ordering-modes-arraymode).
3. [Targeting an element by its key](#targeting-an-element-by-its-key-arangoitem_key), for arrays of **objects**.
4. The [six methods](#the-methods) and their `$init` keys.
5. The [signals](#signals) and the [parent propagation](#propagating-a-change-to-parent-documents).
6. The [migration](#migrating-from-listitemtrait--multifieldtrait) from the legacy traits.

## When to use it

This pattern fits **small ordered lists embedded** in a document: ordered references (`hasPart`, `itemListElement`), labels (`tags`), etc. — when *edges* would be too heavy and order matters.

For numerous, traversable or shared relations, prefer [*edges*](../edges-joins-projection.md).

## The `AQL::ARRAYS` declaration

Each array field is declared when the model is built, next to `AQL::FILTERS`, `AQL::EDGES`, etc.:

```php
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;

$playlists = new Documents( $container,
[
    AQL::COLLECTION => 'Playlist',

    AQL::ARRAYS =>
    [
        'tracks'   => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ], // ordered + counter kept
        'tags'     => ArrayMode::SET ,        // unique, insertion order (shorthand)
        'genres'   => ArrayMode::SORTED_SET , // unique + sorted by value
        'chapters' => [ ArrayMode::LIST , Arango::ITEM_KEY => 'id' ], // array of objects, targeted by their `id`
    ],
]);
```

Each entry is either:

- a **shorthand**: `'tags' => ArrayMode::SET`;
- or a **rich form**: `'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ]`.

An **undeclared** field defaults to `ArrayMode::LIST`, without a counter.

### The counter (`Arango::COUNTER`)

If a field declares a `COUNTER`, the named attribute is **recomputed automatically** (`LENGTH(...)`) on every mutation. Handy to sort/filter on the list size without unwinding the array (e.g. `numberOfTracks`).

### Default value on creation

Declared array fields are **initialized to `[]` on document creation** (and their `counter` to `0`): `insert()` — and the INSERT branch of `upsert()` — seeds these defaults for any field declared in `AQL::ARRAYS` that the payload omits. A brand-new document is therefore always ready for `arrayInsert`/`arrayContains` with no missing-field special case. Explicitly provided values are never overwritten.

## Ordering modes (`ArrayMode`)

The mode drives **both uniqueness AND sorting** with a single setting — so you never pass a `unique`/`sorted` flag at call time:

| Mode | Duplicates | Order | `arrayMove` | Insertion AQL |
|---|---|---|---|---|
| `ArrayMode::LIST` | allowed | insertion | ✅ | `APPEND(doc.f, @value)` |
| `ArrayMode::SET` | no | insertion | ✅ | `APPEND(doc.f, @value, true)` |
| `ArrayMode::SORTED_SET` | no | by value | ❌ (throws) | `SORTED_UNIQUE(APPEND(doc.f, @value, true))` |

> On a `SORTED_SET` field, [`arrayMove()`](#arraymove) is meaningless (sorting by value overrides any position) and throws an `UnsupportedOperationException`.

## Targeting an element by its key (`Arango::ITEM_KEY`)

By default, every operation designates an element by **value equality**: `REMOVE_VALUE(doc.tracks, @value)` removes the element whose value is, byte for byte, the one that was sent. For strings (`'jazz'`, `'track-A'`) that is perfect. For **objects**, it is a trap.

**The setting.** The `playlist-42` playlist carries chapters, and each chapter is an object:

```json
{
  "_key"     : "playlist-42",
  "chapters" : [ { "id": "c1", "title": "Intro", "rating": 3 } ]
}
```

**The situation.** The client wants to remove chapter `c1`. Without an item key, it has a single way to designate it: send the whole object back.

```php
$playlists->arrayRemove([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'chapters',
    Arango::VALUE => [ 'id' => 'c1' , 'title' => 'Intro' , 'rating' => 3 ], // its copy, in full
]);
```

Two things go wrong, and **silently**:

- **The copy ages.** As soon as a write has touched the chapter — a rating that changes, an attribute added by a migration — the copy the client holds no longer equals the one in the database. The equality fails, the operation matches nothing. And it does not complain: it returns an unchanged document, not an error.
- **The object does not fit in a URL.** `DELETE /playlists/42/chapters/{value}` expects a segment, not JSON. It has to travel in the request body, which closes the natural REST routes.

**The declaration.** A field may name the attribute that identifies each of its elements:

```php
AQL::ARRAYS =>
[
    'chapters' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfChapters' , Arango::ITEM_KEY => 'id' ],
    'tags'     => ArrayMode::SET , // no key declared → by-value targeting, as before
]
```

From then on, `Arango::VALUE` is no longer the element: it is **its key**.

```php
$playlists->arrayRemove([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'chapters',
    Arango::VALUE => 'c1', // two characters, which fit in a URL
]);
```

> Not to be confused with `Arango::KEY`, which identifies the **document** (`_key` by default). `Arango::ITEM_KEY` identifies an element **inside** one of its array fields.

### What it changes in the generated AQL

Both columns, side by side:

| Operation | Without a key (by value) | With `ITEM_KEY => 'id'` |
|---|---|---|
| [`arrayContains`](#arraycontains) | `POSITION(doc.f, @value)` | `doc.f[? FILTER CURRENT.id == @value]` |
| [`arrayRemove`](#arrayremove) | `REMOVE_VALUE(doc.f, @value)` | `doc.f[* FILTER CURRENT.id != @value]` |
| [`arrayMove`](#arraymove) | `REMOVE_VALUE` then rebuild | `FIRST(…)` then a **guarded** rebuild |
| [`arrayUpdate`](#arrayupdate) | *(refused — see below)* | `doc.f[* RETURN CURRENT.id == @value ? MERGE(CURRENT, @patch) : CURRENT]` |
| [`arrayInsert`](#arrayinsert) | `APPEND(doc.f, @value)` | *identical* — an insert carries the whole element, there is nothing to look up |
| [`arrayPurgeRef`](#arraypurgeref) | `REMOVE_VALUE(doc.f, @value)` | *identical* — see the [limits](#the-limits) |

**Backward compatibility.** A field declaring no `ITEM_KEY` keeps exactly its behaviour: the generated AQL is identical, byte for byte.

### A key that matches nothing

The two operations targeting an **existing** element — `arrayMove` and `arrayUpdate` — are **guarded**: an unknown key rewrites the array **as it is**. `arrayMove` does not insert a `null` at the requested position, `arrayUpdate` merges nothing. The returned array (`RETURN NEW`) is then enough to notice the miss — that is how the [HTTP controller](../controllers/README.md#arraypropertycontroller) answers `404` without a single extra query.

### Per-call override

The key can also be passed at call time, through `Arango::ITEM_KEY` in `$init`. It then wins over the field configuration — handy for an undeclared field, or for a call that knows better:

```php
$playlists->arrayRemove([
    Arango::OWNER    => 'playlist-42',
    Arango::FIELD    => 'members',
    Arango::VALUE    => 7,
    Arango::ITEM_KEY => 'memberId', // this field declares nothing, the call decides
]);
```

### The limits

One by one:

- **`arrayPurgeRef` stays by value.** The collection-wide purge ignores the `ITEM_KEY` and compares structurally. That is consistent with its use (purging a shared **reference**, usually a scalar), but it is a known gap: there is no purge by key yet.
- **The comparison is typed.** `CURRENT.id == @value` is strict in AQL: a numeric key `1` requested from a URL — hence `"1"`, a string — matches nothing. Declare textual keys, or convert before the call.
- **`arrayContains` takes one key, not a list.** Only `arrayRemove` accepts several keys at once (`CURRENT.id NOT IN @value`).
- **The key name is interpolated verbatim** into the AQL — the array-expansion helpers escape nothing. It is therefore validated by `assertAttributeName()` **whatever its origin** (configuration or per-call override): a name that is not a safe attribute identifier throws a `ValidationException` before it reaches any query.

## The methods

| Method | Role | Returns |
|---|---|---|
| [`arrayInsert`](#arrayinsert) | add one or several values | `?object` (modified doc) |
| [`arrayRemove`](#arrayremove) | remove one or several values | `?object` |
| [`arrayMove`](#arraymove) | move a value to a position | `?object` |
| [`arrayUpdate`](#arrayupdate) | merge a patch into an element, **in place** | `?object` |
| [`arrayContains`](#arraycontains) | test the presence of a value | `bool` |
| [`arrayPurgeRef`](#arraypurgeref) | remove a value from **every** document that contains it | `object[]` or `int` |

### Common `$init` keys

| Key | Default | Description |
|---|---|---|
| `Arango::OWNER` | — | The value identifying the document to modify. |
| `Arango::KEY` | `_key` | The attribute used to locate the document (e.g. `Prop::ID`, `'name'`). |
| `Arango::PREFIX` | `doc` | The AQL document alias. |
| `Arango::FIELD` | — | The targeted array field. |
| `Arango::VALUE` | — | The element(s) involved — or **its key**, when the field declares an `ITEM_KEY`. |
| `Arango::ITEM_KEY` | *(field config)* | Per-call override of the attribute identifying an element. See [targeting by key](#targeting-an-element-by-its-key-arangoitem_key). |
| `Arango::TOUCH` | `true` | Set `modified` to `DATE_ISO8601(DATE_NOW())`; `false` to leave it untouched. |
| `Arango::DEBUG` | `false` | Log the compiled AQL query. |

> **`OWNER`/`VALUE` convention**: here `OWNER` locates the document and `VALUE` is the array element. (Elsewhere in the library `VALUE` locates the document; `OWNER` disambiguates this for array operations.)

### `arrayInsert`

Adds one or several values. `VALUE` accepts a scalar or an array (its elements are appended, never nested). Extra keys: `Arango::SIDE` (`Side::LEFT` to prepend, `Side::RIGHT` to append, the default), `Arango::MODE` (per-call mode override).

```php
use oihana\arango\models\enums\Side;

$playlists->arrayInsert([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'tracks',
    Arango::VALUE => [ 'track-A' , 'track-B' ],
]);
```
```aql
FOR doc IN @@collection FILTER doc._key == @key
  LET __arr = APPEND(doc.tracks, @value)
  UPDATE doc WITH { tracks: __arr, numberOfTracks: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) }
  IN @@collection RETURN NEW
```

- `tags` (SET) → `APPEND(doc.tags, @value, true)` (uniqueness applied automatically).
- `genres` (SORTED_SET) → `SORTED_UNIQUE(APPEND(doc.genres, @value, true))`.
- `Side::LEFT` → operands swapped: `APPEND(@value, doc.tracks)`.

### `arrayRemove`

Removes one or several values. Scalar → `REMOVE_VALUE`; array → `REMOVE_VALUES`.

```php
$playlists->arrayRemove([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'tracks',
    Arango::VALUE => 'track-A',
]);
// → LET __arr = REMOVE_VALUE(doc.tracks, @value)
```

On a field with an item key, `VALUE` holds the **key(s)** to drop:

```aql
LET __arr = doc.chapters[* FILTER CURRENT.id != @value]      -- one key
LET __arr = doc.chapters[* FILTER CURRENT.id NOT IN @value]  -- a list of keys
```

### `arrayMove`

Moves an existing value to a position (zero-based index, `Arango::POSITION` key). Unsupported on a `SORTED_SET` field.

```php
$playlists->arrayMove([
    Arango::OWNER    => 'playlist-42',
    Arango::FIELD    => 'tracks',
    Arango::VALUE    => 'track-A',
    Arango::POSITION => 2,
]);
```
```aql
LET __rm  = REMOVE_VALUE(doc.tracks, @value)
LET __arr = APPEND( PUSH( SLICE(__rm, 0, 2), @value, true ), SLICE(__rm, 2) )
```

On a field with an item key, the element has to be **looked up** before it can be re-inserted — and the whole rebuild is guarded on that lookup:

```aql
LET __el  = FIRST(doc.chapters[* FILTER CURRENT.id == @value])
LET __rm  = doc.chapters[* FILTER CURRENT.id != @value]
LET __arr = __el == null ? doc.chapters
          : APPEND( PUSH( SLICE(__rm, 0, 2), __el, true ), SLICE(__rm, 2) )
```

An unknown key therefore leaves `__el` at `null`, and the array is rewritten **unchanged** — never a phantom `null` at the requested position.

### `arrayContains`

Tests whether a value is present in a document's array. Returns a `bool`.

```php
$playlists->arrayContains([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'tags',
    Arango::VALUE => 'jazz',
]); // bool
```
```aql
RETURN LENGTH(FOR doc IN @@collection FILTER doc._key == @key && POSITION(doc.tags, @value) RETURN 1) > 0
```

On a field with an item key, the membership test becomes the boolean expansion operator — an object is thus found from its key alone:

```aql
… FILTER doc._key == @key && doc.chapters[? FILTER CURRENT.id == @value] …
```

### `arrayUpdate`

Merges a **partial patch** into the element carrying the given key — an **in-place** edit, where the other methods only add, remove or reorder whole elements. The patch travels through the `Arango::PATCH` key.

**The situation.** Chapter `c1` of `playlist-42` must move to rating 5, and gain an annotation. Nothing else may move.

```php
$playlists->arrayUpdate([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'chapters',
    Arango::VALUE => 'c1',                                // the element key
    Arango::PATCH => [ 'rating' => 5 , 'note' => 'live' ], // what changes
]);
```
```aql
LET __arr = doc.chapters[* RETURN CURRENT.id == @value ? MERGE(CURRENT, @patch) : CURRENT]
UPDATE doc WITH { chapters: __arr, numberOfChapters: LENGTH(__arr), modified: … }
```

Every element is projected back, only the one carrying the key is merged. The merge is **partial**: `rating` is overwritten, `note` is added, `title` survives untouched — and both the array order and the siblings are preserved.

**An item key is mandatory.** On a field targeted by value, `arrayUpdate` throws an `UnsupportedOperationException` instead of serving a half-working operation:

> Designating the element by its value would require holding a byte-for-byte copy of it — which **the very patch being applied invalidates**. The second identical call would match nothing. Better to refuse than to emit an operation that only works once. (An `Arango::ITEM_KEY` passed at call time is enough to unblock an undeclared field.)

**The field invariant is re-applied.** A patch can make two elements identical, so `ArrayMode::SET` wraps the result in `UNIQUE()` and `ArrayMode::SORTED_SET` in `SORTED_UNIQUE()` — both accept objects. Unlike `arrayMove`, there is **no** `SORTED_SET` guard: an in-place edit does not fight the sort order.

Finally, an unknown key rewrites the array unchanged (nothing is merged), which is what lets the [HTTP controller](../controllers/README.md#arraypropertycontroller) answer `404`.

### `arrayPurgeRef`

Removes a value from **every** document of the collection that contains it — typically to purge a now-stale reference (an item deleted from the catalogue).

```php
// Track "track-A" is deleted: remove it from every playlist.
$playlists->arrayPurgeRef([
    Arango::FIELD => 'tracks',
    Arango::VALUE => 'track-A',
]);
```
```aql
FOR doc IN @@collection FILTER POSITION(doc.tracks, @value)
  LET __arr = REMOVE_VALUE(doc.tracks, @value)
  UPDATE doc WITH { tracks: __arr, numberOfTracks: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) }
  IN @@collection RETURN NEW
```

The return shape is **your choice**:

- by default → `object[]` (the modified documents);
- with `Arango::COUNT => true` → an `int` (the number of affected documents, without materialising the documents).

## Signals

The single-document write methods (`arrayInsert`/`arrayRemove`/`arrayMove`/`arrayUpdate`) emit the `beforeUpdate` / `afterUpdate` signals of the [`HasUpdateSignals`](../models.md#lifecycle-and-hooks) trait, exactly like the other write methods of the model. `arrayContains` is a read: no signal. `arrayPurgeRef` emits none either — it is a collection-wide operation, which does not fit the "one updated document" contract.

## Propagating a change to parent documents

When a child changes, you may want to "touch" the parent documents that reference it (e.g. to invalidate a cache). **The library does not sweep the database for that**: it emits a signal, and it is up to your application to react.

```php
// When a "track" is updated, refresh the playlists that contain it.
$tracks->afterUpdate->connect( function( AfterUpdate $event ) use ( $playlists )
{
    $playlists->arrayPurgeRef([ /* … */ ]); // or a targeted updateDate on the relevant parents
});
```

The benefit: propagation stays **explicit, testable and under your control**, rather than a massive AQL sweep hidden inside the model.

## Migrating from `ListItemTrait` / `MultiFieldTrait`

`DocumentsArrayTrait` replaces the legacy `ListItemTrait` and `MultiFieldTrait` traits (removed). Mapping:

| Legacy | New |
|---|---|
| `insertListItems` / `insertInMultiField` | `arrayInsert` |
| `deleteListItem` / `deleteListItemAll` / `deleteInMultiField` | `arrayRemove` |
| `updateInMultiField` | `arrayMove` |
| `existsInMultiField` | `arrayContains` |
| `deleteReverseInMultiField` | `arrayPurgeRef` |
| `updateDateParentMultiField` | *(removed — see [parent propagation](#propagating-a-change-to-parent-documents))* |

The counter (legacy `num`) and the insertion side (`left`/`right`) are now declared via `Arango::COUNTER` and the `Side` enum.

## See also

- [`Documents` and `Edges` models](../models.md) — the high-level layer and its `AQL::*` key catalogue.
- [Edges and joins projection](../edges-joins-projection.md) — the *edges* alternative for relations.
- [Enums reference](../enums.md) — `AQL`, `Arango`, `ArrayMode`, `Side`.
