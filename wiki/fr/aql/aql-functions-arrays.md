# Fonctions de tableaux `db/functions/arrays/`

Le sous-dossier [`api/src/oihana/arango/db/functions/arrays/`](../../../../api/src/oihana/arango/db/functions/arrays/) regroupe **19 fonctions** qui correspondent aux *array functions* natives d'AQL — comptage, accès indexé, manipulation d'ordre, modification, suppression.

> Les fonctions d'agrégation numérique sur tableau (`SUM`, `AVERAGE`, `MIN`, `MAX`, `MEDIAN`, `PERCENTILE`, `PRODUCT`) vivent dans [Fonctions numériques](aql-functions-numerics.md) — historiquement classées par ArangoDB côté *numeric*.

## Sommaire

| Catégorie | Fonctions |
|---|---|
| Comptage | `count`, `countDistinct`, `length` |
| Accès indexé | `first`, `last`, `nth`, `position` |
| Manipulation d'ordre | `reverse`, `sorted`, `sortedUnique`, `unique`, `slice` |
| Modification | `pop`, `shift`, `push`, `unshift`, `append` |
| Suppression | `removeValue`, `removeValues` |

## Comptage

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `count` | `(mixed $expression)` | `COUNT(<expr>)` |
| `countDistinct` | `(mixed $anyArray)` | `COUNT_DISTINCT(<array>)` |
| `length` | `(mixed $expression)` | `LENGTH(<expr>)` |

`count` et `length` sont alias dans AQL. Choisir l'un ou l'autre selon ce qui se lit le mieux dans le contexte.

```php
use function oihana\arango\db\functions\arrays\count         ;
use function oihana\arango\db\functions\arrays\countDistinct ;
use function oihana\arango\db\functions\arrays\length        ;

count        ( 'doc.tags'       ) ;     // "COUNT(doc.tags)"
countDistinct( 'doc.categories' ) ;     // "COUNT_DISTINCT(doc.categories)"
length       ( 'doc.items'      ) ;     // "LENGTH(doc.items)"
```

## Accès indexé

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `first` | `(mixed $anyArray)` | `FIRST(<array>)` |
| `last` | `(mixed $anyArray)` | `LAST(<array>)` |
| `nth` | `(mixed $anyArray, int $position)` | `NTH(<array>, <position>)` |
| `position` | `(mixed $anyArray, int\|string $search, bool $returnIndex = false)` | `POSITION(<array>, <search>[, <returnIndex>])` |

`position` renvoie soit un booléen (présent ou pas), soit l'index si `$returnIndex` est `true`.

```php
use function oihana\arango\db\functions\arrays\first    ;
use function oihana\arango\db\functions\arrays\nth      ;
use function oihana\arango\db\functions\arrays\position ;

first   ( 'doc.tags'                   ) ;     // "FIRST(doc.tags)"
nth     ( 'doc.scores' , 2             ) ;     // "NTH(doc.scores, 2)"
position( 'doc.tags' , "'featured'"    ) ;     // "POSITION(doc.tags, 'featured')"
position( 'doc.tags' , "'featured'" , true ) ; // "POSITION(doc.tags, 'featured', true)"
```

## Manipulation d'ordre

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `reverse` | `(mixed $anyArray)` | `REVERSE(<array>)` |
| `sorted` | `(mixed $anyArray)` | `SORTED(<array>)` |
| `sortedUnique` | `(mixed $anyArray)` | `SORTED_UNIQUE(<array>)` |
| `unique` | `(mixed $anyArray)` | `UNIQUE(<array>)` |
| `slice` | `(mixed $anyArray, int $start, ?int $length)` | `SLICE(<array>, <start>[, <length>])` |

`sorted` et `sortedUnique` produisent un tableau trié (avec ou sans déduplication). `unique` déduplique sans trier (préserve l'ordre d'apparition).

```php
use function oihana\arango\db\functions\arrays\sorted ;
use function oihana\arango\db\functions\arrays\unique ;
use function oihana\arango\db\functions\arrays\slice  ;

sorted( 'doc.scores'         ) ;     // "SORTED(doc.scores)"
unique( 'doc.tags'           ) ;     // "UNIQUE(doc.tags)"
slice ( 'doc.items' , 0 , 5  ) ;     // "SLICE(doc.items, 0, 5)"
```

## Modification

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `pop` | `(mixed $anyArray)` | `POP(<array>)` (retire le dernier) |
| `shift` | `(mixed $anyArray)` | `SHIFT(<array>)` (retire le premier) |
| `push` | `(mixed $anyArray, mixed $value, bool $unique = false)` | `PUSH(<array>, <value>[, <unique>])` (ajoute à la fin) |
| `unshift` | `(mixed $anyArray, mixed $value, bool $unique = false)` | `UNSHIFT(<array>, <value>[, <unique>])` (ajoute au début) |
| `append` | `(mixed $anyArray, mixed $values, bool $unique = false)` | `APPEND(<array>, <values>[, <unique>])` (ajoute un tableau) |

Le paramètre `$unique = true` empêche l'ajout d'un élément déjà présent dans le tableau.

```php
use function oihana\arango\db\functions\arrays\push   ;
use function oihana\arango\db\functions\arrays\append ;

push  ( 'doc.tags' , "'urgent'" , true        ) ;     // "PUSH(doc.tags, 'urgent', true)"
append( 'doc.tags' , [ "'a'" , "'b'" ]        ) ;     // "APPEND(doc.tags, ['a', 'b'])"
```

## Suppression

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `removeValue` | `(string $anyArray, mixed $value, ?int $limit)` | `REMOVE_VALUE(<array>, <value>[, <limit>])` |
| `removeValues` | `(string $anyArray, string $values)` | `REMOVE_VALUES(<array>, <values>)` |

`removeValue` retire toutes les occurrences (ou les `$limit` premières) d'une valeur. `removeValues` retire toutes les occurrences de plusieurs valeurs.

## Composition typique

Lister les documents avec exactement 3 *tags*, dont au moins un est `'urgent'` :

```php
use function oihana\arango\db\operators\equal    ;
use function oihana\arango\db\operators\anyEqual ;
use function oihana\arango\db\operations\aqlFilter ;
use function oihana\arango\db\functions\arrays\count ;

aqlFilter
([
    equal   ( count( 'doc.tags' ) , 3            ) ,
    anyEqual( 'doc.tags'          , "'urgent'"   ) ,
]) ;
// "FILTER COUNT(doc.tags) == 3 && doc.tags ANY == 'urgent'"
```

## Voir aussi

- [Fonctions numériques `db/functions/numerics/`](aql-functions-numerics.md) — agrégations numériques sur tableau (`SUM`, `AVG`, `MIN`, `MAX`, `MEDIAN`).
- [Opérateurs quantifiés](aql-operators.md#opérateurs-quantifiés-all) — `ALL`, `ANY`, `NONE` sur tableau.
- [Construire une requête AQL pas à pas](aql-building-queries.md).
- [Documentation officielle AQL — Array functions](https://docs.arangodb.com/stable/aql/functions/array/).
