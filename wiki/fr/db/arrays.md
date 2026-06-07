# Champs-tableaux embarqués `AQL::ARRAYS`

> Gérer un **tableau stocké à l'intérieur d'un document** (ajouter, retirer, déplacer, tester) côté serveur, de façon atomique, en une seule requête AQL `UPDATE`.

Le trait [`DocumentsArrayTrait`](../../../src/oihana/arango/models/traits/DocumentsArrayTrait.php) — composé par [`Documents`](../models.md) — expose un petit jeu de méthodes pour muter un champ-liste embarqué (par ex. `tracks`, `tags`, `hasPart`…) sans rapatrier le tableau côté PHP. Le comportement de chaque champ (ordre, unicité, compteur de longueur optionnel) se déclare **une seule fois** sur le modèle, via l'option `AQL::ARRAYS`.

Cette page documente :

1. [Quand l'utiliser](#quand-lutiliser) (vs *edges*).
2. La [déclaration `AQL::ARRAYS`](#déclaration-aqlarrays) et les [modes d'ordre](#modes-dordre-arraymode).
3. Les [cinq méthodes](#les-méthodes) et leurs clés `$init`.
4. Les [signaux](#signaux) et la [propagation aux parents](#propager-une-modification-aux-documents-parents).
5. La [migration](#migration-depuis-listitemtrait--multifieldtrait) depuis les anciens traits.

## Quand l'utiliser

Ce pattern convient aux **petites listes ordonnées embarquées** dans un document : références ordonnées (`hasPart`, `itemListElement`), étiquettes (`tags`), etc. — quand des *edges* seraient trop lourds et que l'ordre compte.

Pour des relations nombreuses, traversables ou partagées, préférez les [*edges*](../edges-joins-projection.md).

## Déclaration `AQL::ARRAYS`

Chaque champ-tableau est déclaré à la construction du modèle, à côté de `AQL::FILTERS`, `AQL::EDGES`, etc. :

```php
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;

$playlists = new Documents( $container,
[
    AQL::COLLECTION => 'Playlist',

    AQL::ARRAYS =>
    [
        'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ], // ordonné + compteur tenu
        'tags'   => ArrayMode::SET ,        // unique, ordre d'insertion (raccourci)
        'genres' => ArrayMode::SORTED_SET , // unique + trié par valeur
    ],
]);
```

Chaque entrée est :

- soit un **raccourci** : `'tags' => ArrayMode::SET` ;
- soit une **forme riche** : `'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ]`.

Un champ **non déclaré** est traité par défaut comme `ArrayMode::LIST`, sans compteur.

### Le compteur (`Arango::COUNTER`)

Si un champ déclare un `COUNTER`, l'attribut nommé est **recalculé automatiquement** (`LENGTH(...)`) à chaque mutation. Pratique pour trier/filtrer sur la taille de la liste sans dérouler le tableau (par ex. `numberOfTracks`).

## Modes d'ordre (`ArrayMode`)

Le mode pilote **l'unicité ET le tri** en un seul réglage — vous ne passez donc jamais de flag `unique`/`sorted` à l'appel :

| Mode | Doublons | Ordre | `arrayMove` | AQL d'insertion |
|---|---|---|---|---|
| `ArrayMode::LIST` | autorisés | insertion | ✅ | `APPEND(doc.f, @value)` |
| `ArrayMode::SET` | non | insertion | ✅ | `APPEND(doc.f, @value, true)` |
| `ArrayMode::SORTED_SET` | non | par valeur | ❌ (lève une exception) | `SORTED_UNIQUE(APPEND(doc.f, @value, true))` |

> Sur un champ `SORTED_SET`, [`arrayMove()`](#arraymove) n'a aucun sens (le tri par valeur écrase toute position) et lève une `UnsupportedOperationException`.

## Les méthodes

| Méthode | Rôle | Retour |
|---|---|---|
| [`arrayInsert`](#arrayinsert) | ajoute une ou plusieurs valeurs | `?object` (doc modifié) |
| [`arrayRemove`](#arrayremove) | retire une ou plusieurs valeurs | `?object` |
| [`arrayMove`](#arraymove) | déplace une valeur à une position | `?object` |
| [`arrayContains`](#arraycontains) | teste la présence d'une valeur | `bool` |
| [`arrayPurgeRef`](#arraypurgeref) | retire une valeur dans **tous** les documents qui la contiennent | `object[]` ou `int` |

### Clés `$init` communes

| Clé | Défaut | Description |
|---|---|---|
| `Arango::OWNER` | — | La valeur qui identifie le document à modifier. |
| `Arango::KEY` | `_key` | L'attribut de localisation du document (ex. `Prop::ID`, `'name'`). |
| `Arango::PREFIX` | `doc` | L'alias AQL du document. |
| `Arango::FIELD` | — | Le champ-tableau visé. |
| `Arango::VALUE` | — | L'élément (ou les éléments) concerné(s). |
| `Arango::TOUCH` | `true` | Met `modified` à `DATE_ISO8601(DATE_NOW())` ; `false` pour ne pas y toucher. |
| `Arango::DEBUG` | `false` | Journalise la requête AQL compilée. |

> **Convention `OWNER`/`VALUE`** : ici `OWNER` localise le document et `VALUE` est l'élément du tableau. (Ailleurs dans la lib, `VALUE` localise le document ; `OWNER` lève l'ambiguïté pour les opérations sur tableaux.)

### `arrayInsert`

Ajoute une ou plusieurs valeurs. `VALUE` accepte un scalaire ou un tableau (ses éléments sont ajoutés, jamais imbriqués). Clés additionnelles : `Arango::SIDE` (`Side::LEFT` pour préfixer, `Side::RIGHT` par défaut pour suffixer), `Arango::MODE` (override ponctuel du mode).

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

- `tags` (SET) → `APPEND(doc.tags, @value, true)` (unique appliqué automatiquement).
- `genres` (SORTED_SET) → `SORTED_UNIQUE(APPEND(doc.genres, @value, true))`.
- `Side::LEFT` → opérandes inversés : `APPEND(@value, doc.tracks)`.

### `arrayRemove`

Retire une ou plusieurs valeurs. Scalaire → `REMOVE_VALUE` ; tableau → `REMOVE_VALUES`.

```php
$playlists->arrayRemove([
    Arango::OWNER => 'playlist-42',
    Arango::FIELD => 'tracks',
    Arango::VALUE => 'track-A',
]);
// → LET __arr = REMOVE_VALUE(doc.tracks, @value)
```

### `arrayMove`

Déplace une valeur existante à une position (index à base 0, clé `Arango::POSITION`). Non supporté sur un champ `SORTED_SET`.

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

Teste la présence d'une valeur dans le tableau d'un document. Retourne un `bool`.

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

Retire une valeur dans **tous** les documents de la collection qui la contiennent — typiquement pour purger une référence devenue obsolète (un élément supprimé du catalogue).

```php
// Le morceau "track-A" est supprimé : on l'ôte de toutes les playlists.
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

Le retour est **au choix** :

- par défaut → `object[]` (les documents modifiés) ;
- avec `Arango::COUNT => true` → un `int` (le nombre de documents affectés, sans matérialiser les documents).

## Signaux

Les méthodes d'écriture (`arrayInsert`/`arrayRemove`/`arrayMove`/`arrayPurgeRef`) émettent les signaux `beforeUpdate` / `afterUpdate` du trait [`HasUpdateSignals`](../models.md#cycle-de-vie-et-hooks), exactement comme les autres méthodes d'écriture du modèle. `arrayContains` est une lecture : aucun signal.

## Propager une modification aux documents parents

Quand un enfant change, on peut vouloir « toucher » les documents parents qui le référencent (par ex. invalider un cache). **La librairie ne balaie pas la base pour ça** : elle émet un signal, et c'est à votre application d'y réagir.

```php
// Quand un "track" est modifié, on rafraîchit les playlists qui le contiennent.
$tracks->afterUpdate->connect( function( AfterUpdate $event ) use ( $playlists )
{
    $playlists->arrayPurgeRef([ /* … */ ]); // ou un updateDate ciblé sur les parents concernés
});
```

Avantage : la propagation reste **explicite, testable et sous votre contrôle**, plutôt qu'un balayage AQL massif caché dans le modèle.

## Migration depuis `ListItemTrait` / `MultiFieldTrait`

`DocumentsArrayTrait` remplace les anciens traits `ListItemTrait` et `MultiFieldTrait` (supprimés). Correspondance :

| Ancien | Nouveau |
|---|---|
| `insertListItems` / `insertInMultiField` | `arrayInsert` |
| `deleteListItem` / `deleteListItemAll` / `deleteInMultiField` | `arrayRemove` |
| `updateInMultiField` | `arrayMove` |
| `existsInMultiField` | `arrayContains` |
| `deleteReverseInMultiField` | `arrayPurgeRef` |
| `updateDateParentMultiField` | *(supprimé — voir [propagation aux parents](#propager-une-modification-aux-documents-parents))* |

Le compteur (ancien `num`) et le côté d'insertion (`left`/`right`) sont désormais déclarés via `Arango::COUNTER` et l'enum `Side`.

## Voir aussi

- [Modèles `Documents` et `Edges`](../models.md) — la couche haut-niveau et son catalogue de clés `AQL::*`.
- [Projection des edges et joins](../edges-joins-projection.md) — l'alternative *edges* pour les relations.
- [Référence des enums](../enums.md) — `AQL`, `Arango`, `ArrayMode`, `Side`.
