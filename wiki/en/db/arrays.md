# Embedded array fields `AQL::ARRAYS`

> Manage an **array stored inside a document** (add, remove, move, test) server-side, atomically, in a single AQL `UPDATE`.

The [`DocumentsArrayTrait`](../../../src/oihana/arango/models/traits/DocumentsArrayTrait.php) trait — composed by [`Documents`](../models.md) — exposes a small set of methods to mutate an embedded list field (e.g. `tracks`, `tags`, `hasPart`…) without fetching the array back into PHP. The behaviour of each field (ordering, uniqueness, optional length counter) is declared **once** on the model, through the `AQL::ARRAYS` option.

This page documents:

1. [When to use it](#when-to-use-it) (vs *edges*).
2. The [`AQL::ARRAYS` declaration](#the-aqlarrays-declaration) and the [ordering modes](#ordering-modes-arraymode).
3. The [five methods](#the-methods) and their `$init` keys.
4. The [signals](#signals) and the [parent propagation](#propagating-a-change-to-parent-documents).
5. The [migration](#migrating-from-listitemtrait--multifieldtrait) from the legacy traits.

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
        'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ], // ordered + counter kept
        'tags'   => ArrayMode::SET ,        // unique, insertion order (shorthand)
        'genres' => ArrayMode::SORTED_SET , // unique + sorted by value
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

## The methods

| Method | Role | Returns |
|---|---|---|
| [`arrayInsert`](#arrayinsert) | add one or several values | `?object` (modified doc) |
| [`arrayRemove`](#arrayremove) | remove one or several values | `?object` |
| [`arrayMove`](#arraymove) | move a value to a position | `?object` |
| [`arrayContains`](#arraycontains) | test the presence of a value | `bool` |
| [`arrayPurgeRef`](#arraypurgeref) | remove a value from **every** document that contains it | `object[]` or `int` |

### Common `$init` keys

| Key | Default | Description |
|---|---|---|
| `Arango::OWNER` | — | The value identifying the document to modify. |
| `Arango::KEY` | `_key` | The attribute used to locate the document (e.g. `Prop::ID`, `'name'`). |
| `Arango::PREFIX` | `doc` | The AQL document alias. |
| `Arango::FIELD` | — | The targeted array field. |
| `Arango::VALUE` | — | The element(s) involved. |
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

The write methods (`arrayInsert`/`arrayRemove`/`arrayMove`/`arrayPurgeRef`) emit the `beforeUpdate` / `afterUpdate` signals of the [`HasUpdateSignals`](../models.md#lifecycle-and-hooks) trait, exactly like the other write methods of the model. `arrayContains` is a read: no signal.

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
